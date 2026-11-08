<?php

namespace App\Services;

use App\Events\RoomPhaseChanged;
use App\Jobs\CreditFocusForPhase;
use App\Jobs\SendPhasePush;
use App\Models\Room;
use App\Models\RoomPhase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single source of truth for room timer state.
 *
 * User rooms are state-based: phases live in the `rooms` table and every
 * change goes through transition(), which bumps `phase_version` and
 * broadcasts RoomPhaseChanged. Always-on rooms are deterministic: the phase
 * is a pure function of (now, anchor_epoch, config) with no DB writes.
 */
class RoomClockService
{
    public const PHASE_IDLE = 'idle';

    public const PHASE_FOCUS = 'focus';

    public const PHASE_SHORT_BREAK = 'short_break';

    public const PHASE_LONG_BREAK = 'long_break';

    /**
     * Deterministic phase for an always-on room: a pure function of time.
     *
     * The schedule repeats every `long_break_every` cycles. All cycles but the
     * last are focus + short break; the last is focus + long break.
     *
     * @return array{phase: string, phase_ends_at_ms: int, phase_started_at_ms: int, cycle_index: int}
     */
    public function alwaysOnPhase(Room $room, int $nowMs): array
    {
        $anchor = (int) $room->anchor_epoch;
        $focus = $room->focus_minutes * 60;
        $short = $room->short_break_minutes * 60;
        $long = $room->long_break_minutes * 60;
        $every = max(2, (int) $room->long_break_every);

        $elapsed = max(0, intdiv($nowMs, 1000) - $anchor);

        // Length in seconds of the repeating pattern of $every cycles.
        $period = ($every - 1) * ($focus + $short) + ($focus + $long);
        $periodIndex = intdiv($elapsed, $period);
        $inPeriod = $elapsed % $period;
        $periodStart = $periodIndex * $period;

        // Walk the phases inside one period to find where we are.
        $cursor = 0;
        for ($cycle = 0; $cycle < $every; $cycle++) {
            $cycleIndex = $periodIndex * $every + $cycle;

            if ($inPeriod < $cursor + $focus) {
                return $this->alwaysOnState(
                    self::PHASE_FOCUS, $anchor, $periodStart + $cursor, $focus, $cycleIndex,
                );
            }
            $cursor += $focus;

            $isLong = $cycle === $every - 1;
            $breakLength = $isLong ? $long : $short;
            $breakPhase = $isLong ? self::PHASE_LONG_BREAK : self::PHASE_SHORT_BREAK;

            if ($inPeriod < $cursor + $breakLength) {
                return $this->alwaysOnState(
                    $breakPhase, $anchor, $periodStart + $cursor, $breakLength, $cycleIndex,
                );
            }
            $cursor += $breakLength;
        }

        // Unreachable: $inPeriod < $period by construction. Fall back to focus
        // so the function can never return an invalid phase.
        return $this->alwaysOnState(self::PHASE_FOCUS, $anchor, $periodStart, $focus, 0);
    }

    /**
     * State payload for a user room: the stored phase plus server clock.
     *
     * @return array{phase: string, phase_ends_at_ms: int|null, phase_started_at_ms: int|null, cycle_index: int, phase_version: int}
     */
    public function userRoomState(Room $room): array
    {
        return [
            'phase' => $room->phase,
            'phase_ends_at_ms' => $room->phase_ends_at?->getTimestampMs(),
            'phase_started_at_ms' => $room->phase_started_at?->getTimestampMs(),
            'cycle_index' => $room->cycle_index,
            'phase_version' => $room->phase_version,
        ];
    }

    /**
     * Apply a host action or phase expiry to a user room.
     *
     * Always runs inside a transaction with a lock so two concurrent skips
     * cannot both advance: the second sees the bumped version and the changed
     * phase, making the action idempotent.
     *
     * @param  'start'|'pause'|'skip'|'reset'  $action
     * @return array{phase: string, phase_ends_at_ms: int|null, phase_started_at_ms: int|null, cycle_index: int, phase_version: int}
     */
    public function transition(Room $room, string $action, ?int $nowMs = null): array
    {
        $nowMs ??= (int) now()->getTimestampMs();

        return DB::transaction(function () use ($room, $action, $nowMs) {
            /** @var Room $locked */
            $locked = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

            if ($locked->isAlwaysOn()) {
                return $this->alwaysOnPhase($locked, $nowMs);
            }

            switch ($action) {
                case 'start':
                    if ($locked->phase === self::PHASE_IDLE) {
                        $this->applyPhase(
                            $locked,
                            self::PHASE_FOCUS,
                            $nowMs,
                            $locked->focus_minutes,
                        );
                    }
                    break;

                case 'pause':
                    if ($locked->phase !== self::PHASE_IDLE) {
                        $locked->phase = self::PHASE_IDLE;
                        $locked->phase_started_at = null;
                        $locked->phase_ends_at = null;
                        $locked->phase_version++;
                    }
                    break;

                case 'skip':
                    $this->advancePhase($locked, $nowMs);
                    break;

                case 'reset':
                    $locked->phase = self::PHASE_IDLE;
                    $locked->phase_started_at = null;
                    $locked->phase_ends_at = null;
                    $locked->cycle_index = 0;
                    $locked->phase_version++;
                    break;
            }

            $locked->save();

            $state = $this->userRoomState($locked);

            event(new RoomPhaseChanged($locked->id, $locked->slug, $state, (int) now()->getTimestampMs()));

            // PRD 4.10: Web Push on a phase change, for members who opted
            // in. Only a real flip into a running phase counts — pause and
            // reset land on idle and stay silent. ShouldQueueAfterCommit:
            // the fan-out must see the committed phase.
            if ($locked->wasChanged('phase_version') && $locked->phase !== self::PHASE_IDLE) {
                SendPhasePush::dispatch(
                    (int) $locked->id,
                    $locked->slug,
                    $locked->name,
                    $locked->phase,
                );
            }

            // After the broadcast so the phase flip reaches clients before any
            // "+X min focused" credit that this transition may trigger.
            $this->syncPhaseHistory($locked);

            return $state;
        });
    }

    /**
     * Keep `room_phases` in step with the stored timer (PRD 4.8/8).
     *
     * - closes rows whose window ended early (host skip/pause/reset)
     * - opens the row for the running phase (always-on windows are created
     *   lazily by heartbeats and flip detection instead)
     * - dispatches CreditFocusForPhase for every uncredited window whose end
     *   has passed — PRD 7.3's "on demand" credit job. The phase row is the
     *   unit of work, so an extra dispatch is harmless: the job claims the
     *   row atomically.
     *
     * Runs inside transition()'s transaction: the history writes commit with
     * the phase change itself, and with a sync/transactional queue the credit
     * job reads the same connection's view.
     */
    public function syncPhaseHistory(Room $room): void
    {
        if ($room->isAlwaysOn()) {
            return;
        }

        $now = now();

        // Early end: the window's recorded end is still in the future, so the
        // phase did not run to completion (skip/pause/reset). Natural ends
        // keep their exact boundary and are picked up by the query below.
        RoomPhase::query()
            ->where('room_id', $room->id)
            ->whereNull('credited_at')
            ->where('ends_at', '>', $now)
            ->get()
            ->each->update(['ends_at' => $now]);

        if ($room->phase !== self::PHASE_IDLE
            && $room->phase_started_at !== null
            && $room->phase_ends_at !== null) {
            $this->ensurePhaseRecord($room, [
                'type' => $room->phase,
                'started_at' => $room->phase_started_at->format('Y-m-d H:i:s'),
                'ends_at' => $room->phase_ends_at->format('Y-m-d H:i:s'),
                'cycle_index' => (int) $room->cycle_index,
            ]);
        }

        RoomPhase::query()
            ->where('room_id', $room->id)
            ->whereNull('credited_at')
            ->where('ends_at', '<=', $now)
            ->pluck('id')
            ->each(fn ($id) => CreditFocusForPhase::dispatch((int) $id));
    }

    /**
     * Find or create the history row for one phase window. Idempotent under
     * the (room_id, started_at, type) unique key: heartbeats, transitions and
     * always-on flip detection all race to create the same window.
     *
     * @param  array{type: string, started_at: string, ends_at: string, cycle_index: int}  $window
     */
    public function ensurePhaseRecord(Room $room, array $window): RoomPhase
    {
        $existing = $this->findPhaseRecord($room, $window);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return RoomPhase::create([
                'room_id' => $room->id,
                'type' => $window['type'],
                'started_at' => $window['started_at'],
                'ends_at' => $window['ends_at'],
                'cycle_index' => $window['cycle_index'],
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->findPhaseRecord($room, $window)
                ?? throw new RuntimeException('room_phases row disappeared during insert race');
        }
    }

    /**
     * Normalize alwaysOnPhase() output into the ensurePhaseRecord() window
     * shape (absolute ms → naive UTC datetimes, same storage format
     * applyPhase() writes for user rooms).
     *
     * @param  array{phase: string, phase_started_at_ms: int, phase_ends_at_ms: int, cycle_index: int}  $state
     * @return array{type: string, started_at: string, ends_at: string, cycle_index: int}
     */
    public static function windowFromState(array $state): array
    {
        return [
            'type' => $state['phase'],
            'started_at' => date('Y-m-d H:i:s', intdiv($state['phase_started_at_ms'], 1000)),
            'ends_at' => date('Y-m-d H:i:s', intdiv($state['phase_ends_at_ms'], 1000)),
            'cycle_index' => (int) $state['cycle_index'],
        ];
    }

    /**
     * @param  array{type: string, started_at: string, ends_at: string, cycle_index: int}  $window
     */
    private function findPhaseRecord(Room $room, array $window): ?RoomPhase
    {
        return RoomPhase::query()
            ->where('room_id', $room->id)
            ->where('type', $window['type'])
            ->where('started_at', $window['started_at'])
            ->first();
    }

    /**
     * Lazily advance a user room whose phase has expired (fallback for a
     * lost or late AdvancePhase job). Idempotent: does nothing when the
     * phase has not ended or the room is idle.
     */
    public function advanceIfExpired(Room $room, ?int $nowMs = null): bool
    {
        $nowMs ??= (int) now()->getTimestampMs();

        if ($room->isAlwaysOn() || $room->phase === self::PHASE_IDLE) {
            return false;
        }

        if ($room->phase_ends_at === null || $room->phase_ends_at->getTimestampMs() > $nowMs) {
            return false;
        }

        $this->transition($room, 'skip', $nowMs);

        return true;
    }

    /**
     * Move a room to the next phase in the cycle and bump the version.
     */
    private function advancePhase(Room $room, int $nowMs): void
    {
        if ($room->phase === self::PHASE_IDLE) {
            $this->applyPhase($room, self::PHASE_FOCUS, $nowMs, $room->focus_minutes);

            return;
        }

        if ($room->phase === self::PHASE_FOCUS) {
            $isLong = ($room->cycle_index + 1) % max(2, (int) $room->long_break_every) === 0;
            $phase = $isLong ? self::PHASE_LONG_BREAK : self::PHASE_SHORT_BREAK;
            $minutes = $isLong ? $room->long_break_minutes : $room->short_break_minutes;

            $this->applyPhase($room, $phase, $nowMs, $minutes);

            return;
        }

        // Any break -> next focus, cycle complete.
        $room->cycle_index++;
        $this->applyPhase($room, self::PHASE_FOCUS, $nowMs, $room->focus_minutes);
    }

    private function applyPhase(Room $room, string $phase, int $nowMs, int $minutes): void
    {
        $room->phase = $phase;
        $room->phase_started_at = date('Y-m-d H:i:s', intdiv($nowMs, 1000));
        $room->phase_ends_at = date('Y-m-d H:i:s', intdiv($nowMs + $minutes * 60_000, 1000));
        $room->phase_version++;
    }

    /**
     * @return array{phase: string, phase_ends_at_ms: int, phase_started_at_ms: int, cycle_index: int}
     */
    private function alwaysOnState(
        string $phase,
        int $anchor,
        int $phaseStart,
        int $length,
        int $cycleIndex,
    ): array {
        return [
            'phase' => $phase,
            'phase_started_at_ms' => ($anchor + $phaseStart) * 1000,
            'phase_ends_at_ms' => ($anchor + $phaseStart + $length) * 1000,
            'cycle_index' => $cycleIndex,
        ];
    }
}

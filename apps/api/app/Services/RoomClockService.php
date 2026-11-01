<?php

namespace App\Services;

use App\Events\RoomPhaseChanged;
use App\Models\Room;
use Illuminate\Support\Facades\DB;

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

            return $state;
        });
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

<?php

namespace App\Services;

use App\Events\FocusCredited;
use App\Models\FocusSession;
use App\Models\PhaseAttendance;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomPhase;
use App\Models\User;
use App\Models\UserDailyFocus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * PRD 4.8 focus credit and stats.
 *
 * Pipeline: heartbeat → phase_attendance.seconds_present (capped 45 s/beat)
 * → phase end → CreditFocusForPhase → focus_sessions + user_daily_focus rollup
 * → streak maintenance → FocusCredited broadcast (the client's "+X min
 * focused" toast and check-in prompt, PRD 5).
 *
 * Crediting rules, verbatim from the PRD:
 *  - minutes = floor(seconds_present / 60), only if at least 5 minutes
 *  - max 12 credited hours per user per day, in the user's timezone
 *  - breaks are never credited
 */
class FocusCreditService
{
    /** PRD 4.8: heartbeats add at most this much elapsed time per beat. */
    public const MAX_ATTENDANCE_SECONDS = 45;

    /** PRD 4.8: floor(seconds/60), but only at 5 minutes or more. */
    public const MIN_CREDIT_MINUTES = 5;

    /** PRD 4.8: 12 credited hours per user per local day. */
    public const DAILY_CAP_MINUTES = 720;

    /** PRD 4.8: a day counts toward the streak at 25+ credited minutes. */
    public const STREAK_THRESHOLD_MINUTES = 25;

    /** PRD 4.8: the stats heatmap covers 12 weeks. */
    public const HEATMAP_WEEKS = 12;

    public function __construct(private RoomClockService $clock) {}

    /* ----------------------------------------------------------------------
     * Accrual (heartbeat)
     * ---------------------------------------------------------------------- */

    /**
     * PRD 4.8: "Each heartbeat during a focus phase adds elapsed time since
     * the previous heartbeat (capped at 45 s) to phase_attendance."
     *
     * The elapsed window is additionally clamped to the phase window, so a
     * beat that arrives after the phase ended (or straddles its start)
     * credits at most the focus seconds that actually elapsed. Breaks accrue
     * nothing — only focus windows ever have attendance rows.
     *
     * @param  Carbon|null  $previousSeenAt  the member's last_seen_at before this beat (null right after a re-join)
     */
    public function recordAttendance(Room $room, User $user, string $phase, ?Carbon $previousSeenAt): void
    {
        if ($phase !== RoomClockService::PHASE_FOCUS || $previousSeenAt === null) {
            return;
        }

        $window = $this->attendanceWindow($room, $phase);

        if ($window === null) {
            return;
        }

        $record = $this->clock->ensurePhaseRecord($room, $window);

        $from = $previousSeenAt->getTimestamp();
        $to = now()->getTimestamp();

        // Clamp to the window: never before the phase started, never after it
        // ends, never negative, never more than the 45 s heartbeat cap.
        $from = max($from, strtotime($window['started_at']));
        $to = min($to, strtotime($window['ends_at']));
        $seconds = min(self::MAX_ATTENDANCE_SECONDS, max(0, $to - $from));

        if ($seconds <= 0) {
            return;
        }

        PhaseAttendance::firstOrCreate(
            ['phase_id' => $record->id, 'user_id' => $user->id],
            ['seconds_present' => 0],
        );

        PhaseAttendance::query()
            ->where('phase_id', $record->id)
            ->where('user_id', $user->id)
            ->increment('seconds_present', $seconds);
    }

    /**
     * The focus window a heartbeat should accrue into: deterministic for
     * always-on rooms, stored state for user rooms. Null when the room is
     * idle or a user room is missing its window bounds.
     *
     * @return array{type: string, started_at: string, ends_at: string, cycle_index: int}|null
     */
    private function attendanceWindow(Room $room, string $phase): ?array
    {
        if ($room->isAlwaysOn()) {
            $state = $this->clock->alwaysOnPhase($room, (int) now()->getTimestampMs());

            // resolvePhase() and this state are computed from the same clock;
            // belt and braces against a boundary straddling the two calls.
            return $state['phase'] === $phase
                ? RoomClockService::windowFromState($state)
                : null;
        }

        if ($room->phase_started_at === null || $room->phase_ends_at === null) {
            return null;
        }

        return [
            'type' => $room->phase,
            'started_at' => $room->phase_started_at->format('Y-m-d H:i:s'),
            'ends_at' => $room->phase_ends_at->format('Y-m-d H:i:s'),
            'cycle_index' => (int) $room->cycle_index,
        ];
    }

    /* ----------------------------------------------------------------------
     * Crediting (CreditFocusForPhase)
     * ---------------------------------------------------------------------- */

    /**
     * Convert one ended phase's attendance into credited minutes (PRD 4.8).
     *
     * Idempotent three ways: a `credited_at` guard, a cache lock around the
     * whole run, and focus_sessions' unique (user, phase) key — so a duplicate
     * dispatch (sync close + nightly repair can both fire) credits once.
     */
    public function creditPhase(int $phaseId): void
    {
        $phase = RoomPhase::query()->find($phaseId);

        if ($phase === null || $phase->credited_at !== null) {
            return;
        }

        $lock = Cache::lock('focus-credit:'.$phaseId, 15);

        if (! $lock->get()) {
            return;
        }

        try {
            DB::transaction(function () use ($phaseId) {
                /** @var RoomPhase|null $phase */
                $phase = RoomPhase::query()->whereKey($phaseId)->lockForUpdate()->first();

                if ($phase === null || $phase->credited_at !== null) {
                    return;
                }

                if ($phase->type === RoomClockService::PHASE_FOCUS) {
                    $this->creditAttendance($phase);
                }

                // Breaks (and anything else) are simply closed out — PRD 4.8:
                // breaks are never credited.
                $phase->update(['credited_at' => now()]);
            });
        } finally {
            $lock->release();
        }
    }

    private function creditAttendance(RoomPhase $phase): void
    {
        $attendances = PhaseAttendance::query()
            ->where('phase_id', $phase->id)
            ->where('seconds_present', '>=', self::MIN_CREDIT_MINUTES * 60)
            ->lockForUpdate()
            ->get();

        if ($attendances->isEmpty()) {
            return;
        }

        $users = User::query()
            ->whereIn('id', $attendances->pluck('user_id'))
            ->get()
            ->keyBy('id');

        foreach ($attendances as $attendance) {
            $user = $users->get($attendance->user_id);

            if ($user !== null) {
                $this->creditUser($phase, $user, (int) $attendance->seconds_present);
            }
        }
    }

    private function creditUser(RoomPhase $phase, User $user, int $seconds): void
    {
        $minutes = intdiv($seconds, 60);

        if ($minutes < self::MIN_CREDIT_MINUTES) {
            return;
        }

        $localDate = Carbon::parse($phase->ends_at->toDateTimeString(), 'UTC')
            ->setTimezone($user->timezoneName())
            ->toDateString();

        $daily = $this->lockDailyRow($user, $localDate);

        // PRD 4.8: max 12 credited hours per user per local day. Credit what
        // fits; a cap-full day credits nothing more (no session row, no toast).
        $remaining = self::DAILY_CAP_MINUTES - (int) $daily->minutes;

        if ($remaining <= 0) {
            return;
        }

        $credited = min($minutes, $remaining);

        $session = FocusSession::firstOrCreate(
            ['user_id' => $user->id, 'phase_id' => $phase->id],
            [
                'room_id' => $phase->room_id,
                'minutes' => $credited,
                'intention' => $this->currentIntention($phase, $user),
                'local_date' => $localDate,
            ],
        );

        if (! $session->wasRecentlyCreated) {
            return;
        }

        $daily->increment('minutes', $credited);
        $daily->increment('sessions');

        $this->updateStreaks($user);

        // afterCommit: the toast must never beat the row it links to.
        event(new FocusCredited(
            (int) $user->id,
            (int) $session->id,
            $credited,
            (int) $phase->room_id,
            $localDate,
        ));
    }

    /**
     * Lock (or create) the user's daily rollup row inside the caller's
     * transaction: two phases of the same user ending in the same sweep must
     * not race on the unique (user, date) key.
     */
    private function lockDailyRow(User $user, string $localDate): UserDailyFocus
    {
        $daily = UserDailyFocus::query()
            ->where('user_id', $user->id)
            ->where('date', $localDate)
            ->lockForUpdate()
            ->first();

        if ($daily !== null) {
            return $daily;
        }

        try {
            return UserDailyFocus::create([
                'user_id' => $user->id,
                'date' => $localDate,
                'minutes' => 0,
                'sessions' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            return UserDailyFocus::query()
                ->where('user_id', $user->id)
                ->where('date', $localDate)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    /**
     * The intention shown on the session card: whatever the member set if
     * they are still in the room, null once they have left (the member row —
     * and with it the intention — is transient by design, PRD 4.4).
     */
    private function currentIntention(RoomPhase $phase, User $user): ?string
    {
        $intention = RoomMember::query()
            ->where('room_id', $phase->room_id)
            ->where('user_id', $user->id)
            ->value('intention');

        return $intention !== null && $intention !== '' ? $intention : null;
    }

    /* ----------------------------------------------------------------------
     * Streaks (PRD 4.8: a day counts at 25+ credited minutes)
     * ---------------------------------------------------------------------- */

    /**
     * Recompute current/longest streak and last_focus_date from the daily
     * rollup. Full recomputation (rather than increment-on-credit) keeps the
     * numbers correct after missed days and timezone-shifted local dates; the
     * current run may end yesterday (the streak is alive until the local day
     * closes) but never earlier.
     */
    public function updateStreaks(User $user): void
    {
        $qualifying = UserDailyFocus::query()
            ->where('user_id', $user->id)
            ->where('minutes', '>=', self::STREAK_THRESHOLD_MINUTES)
            ->orderByDesc('date')
            ->pluck('date')
            ->all();

        if ($qualifying === []) {
            $user->forceFill([
                'current_streak' => 0,
                'last_focus_date' => null,
            ])->save();

            return;
        }

        $longest = 1;
        $run = 1;
        $ascending = array_reverse($qualifying);

        for ($i = 1, $count = count($ascending); $i < $count; $i++) {
            $consecutive = $this->dayNumber($ascending[$i]) - $this->dayNumber($ascending[$i - 1]) === 1;
            $run = $consecutive ? $run + 1 : 1;
            $longest = max($longest, $run);
        }

        $last = $qualifying[0];
        $current = 0;

        $today = now($user->timezoneName())->toDateString();
        $yesterday = now($user->timezoneName())->subDay()->toDateString();

        if ($last === $today || $last === $yesterday) {
            $current = 1;

            for ($i = 1, $count = count($qualifying); $i < $count; $i++) {
                if ($this->dayNumber($qualifying[$i - 1]) - $this->dayNumber($qualifying[$i]) !== 1) {
                    break;
                }
                $current++;
            }
        }

        $user->forceFill([
            'current_streak' => $current,
            'longest_streak' => max($longest, (int) $user->longest_streak),
            'last_focus_date' => $last,
        ])->save();
    }

    /**
     * The streak as it should be displayed today: a stored run that ended
     * before yesterday is dead even though no credit event has run since.
     */
    public function effectiveStreak(User $user): int
    {
        if ($user->last_focus_date === null) {
            return 0;
        }

        $last = $user->last_focus_date->toDateString();
        $yesterday = now($user->timezoneName())->subDay()->toDateString();

        return $last >= $yesterday ? (int) $user->current_streak : 0;
    }

    /**
     * Whole days since epoch for a 'Y-m-d' date string — UTC has no DST, so
     * integer division is exact and safe against Carbon quirks in either
     * direction (yesterday vs today gaps).
     */
    private function dayNumber(string $date): int
    {
        return intdiv(strtotime($date), 86400);
    }
}

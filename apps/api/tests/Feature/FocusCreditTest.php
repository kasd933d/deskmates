<?php

namespace Tests\Feature;

use App\Events\FocusCredited;
use App\Models\FocusSession;
use App\Models\PhaseAttendance;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomPhase;
use App\Models\User;
use App\Models\UserDailyFocus;
use App\Services\FocusCreditService;
use App\Services\PresenceService;
use App\Services\RoomClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Milestone 5 (PRD 4.8): heartbeats accrue focus attendance (45 s cap),
 * phase ends credit minutes (floor, 5-minute minimum, 12-hour cap, user
 * timezone days), daily rollups and streaks follow, and the stats, heatmap,
 * check-in and leaderboard endpoints report it all.
 */
class FocusCreditTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeRoom(User $host, array $overrides = []): Room
    {
        return Room::create(array_merge([
            'name' => 'Focus Credit Room',
            'type' => Room::TYPE_PUBLIC,
            'host_id' => $host->id,
            'focus_minutes' => 25,
            'short_break_minutes' => 5,
            'long_break_minutes' => 15,
            'long_break_every' => 4,
            'last_active_at' => now(),
        ], $overrides));
    }

    private function join(Room $room, User $user): void
    {
        RoomMember::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'joined_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /** One heartbeat through the real service (the HTTP route adds throttling). */
    private function beat(Room $room, User $user): void
    {
        app(PresenceService::class)->heartbeat($room, $user, true);
    }

    /** A finished focus window with no attendance yet. */
    private function focusPhase(Room $room, string $start, string $end): RoomPhase
    {
        return RoomPhase::create([
            'room_id' => $room->id,
            'type' => RoomClockService::PHASE_FOCUS,
            'started_at' => $start,
            'ends_at' => $end,
            'cycle_index' => 0,
        ]);
    }

    private function attend(RoomPhase $phase, User $user, int $seconds): PhaseAttendance
    {
        return PhaseAttendance::create([
            'phase_id' => $phase->id,
            'user_id' => $user->id,
            'seconds_present' => $seconds,
        ]);
    }

    /* ----------------------------------------------------------------------
     * Accrual (PRD 4.8)
     * ---------------------------------------------------------------------- */

    public function test_heartbeats_accrue_focus_attendance_with_a_45_second_cap(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00'));

        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        app(RoomClockService::class)->transition($room, 'start');
        $room->refresh();

        $this->assertSame('focus', $room->phase);
        $this->join($room, $host);

        // Beat 1, 20 s after joining: the full gap counts.
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:20'));
        $this->beat($room, $host);

        // Beat 2, ten minutes later: capped at 45 s (PRD 4.8).
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:10:20'));
        $this->beat($room, $host);

        $phase = RoomPhase::query()->where('room_id', $room->id)->firstOrFail();
        $this->assertSame('focus', $phase->type);

        $attendance = PhaseAttendance::query()
            ->where('phase_id', $phase->id)
            ->where('user_id', $host->id)
            ->firstOrFail();

        $this->assertSame(65, $attendance->seconds_present); // 20 + capped 45
    }

    public function test_heartbeats_accrue_nothing_during_a_break(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00'));

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $clock = app(RoomClockService::class);

        $clock->transition($room, 'start');
        $clock->transition($room, 'skip'); // focus → short break
        $room->refresh();

        $this->assertSame('short_break', $room->phase);
        $this->join($room, $host);

        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:30'));
        $this->beat($room, $host);

        $this->assertSame(0, PhaseAttendance::query()->count());
    }

    public function test_the_heartbeat_endpoint_wires_up_attendance_accrual(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00'));

        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        app(RoomClockService::class)->transition($room, 'start');
        $this->join($room, $host);

        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:20'));
        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/heartbeat", ['visible' => true])
            ->assertOk();

        $attendance = PhaseAttendance::query()->where('user_id', $host->id)->firstOrFail();
        $this->assertSame(20, $attendance->seconds_present);
    }

    /* ----------------------------------------------------------------------
     * Crediting rules (PRD 4.8)
     * ---------------------------------------------------------------------- */

    public function test_credit_requires_five_minutes_and_floors_the_result(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00'));

        $almost = User::factory()->create();
        $exactly = User::factory()->create();
        $room = $this->makeRoom($almost);

        $phase = $this->focusPhase($room, '2026-11-06 09:30:00', '2026-11-06 09:55:00');

        // 4 min 59 s → below the floor: no session at all.
        $this->attend($phase, $almost, 299);
        // Exactly five minutes → floor(300 / 60) = 5 credited.
        $this->attend($phase, $exactly, 300);

        app(FocusCreditService::class)->creditPhase($phase->id);

        $this->assertNotNull($phase->fresh()->credited_at);

        $session = FocusSession::query()->firstOrFail();
        $this->assertSame((int) $exactly->id, (int) $session->user_id);
        $this->assertSame(5, $session->minutes);
        $this->assertSame('2026-11-06', $session->local_date);

        $daily = UserDailyFocus::query()->firstOrFail();
        $this->assertSame(5, $daily->minutes);
        $this->assertSame(1, $daily->sessions);
    }

    public function test_breaks_are_never_credited(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00'));

        $user = User::factory()->create();
        $room = $this->makeRoom($user);

        $phase = RoomPhase::create([
            'room_id' => $room->id,
            'type' => RoomClockService::PHASE_SHORT_BREAK,
            'started_at' => '2026-11-06 09:55:00',
            'ends_at' => '2026-11-06 10:00:00',
        ]);
        $this->attend($phase, $user, 3000); // planted defensively — accrual never writes these

        app(FocusCreditService::class)->creditPhase($phase->id);

        $this->assertNotNull($phase->fresh()->credited_at);
        $this->assertSame(0, FocusSession::query()->count());
        $this->assertSame(0, UserDailyFocus::query()->count());
    }

    public function test_the_daily_cap_stops_crediting_at_twelve_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 20:00:00'));

        $user = User::factory()->create();
        $room = $this->makeRoom($user);
        $credit = app(FocusCreditService::class);

        // Already 700 minutes into the local day.
        UserDailyFocus::create(['user_id' => $user->id, 'date' => '2026-11-06', 'minutes' => 700, 'sessions' => 3]);

        $first = $this->focusPhase($room, '2026-11-06 09:00:00', '2026-11-06 09:25:00');
        $this->attend($first, $user, 300); // wants 5, 20 remain → 5
        $credit->creditPhase($first->id);

        $second = $this->focusPhase($room, '2026-11-06 09:30:00', '2026-11-06 09:55:00');
        $this->attend($second, $user, 1200); // wants 20, only 15 remain → 15
        $credit->creditPhase($second->id);

        $third = $this->focusPhase($room, '2026-11-06 10:00:00', '2026-11-06 10:25:00');
        $this->attend($third, $user, 600); // cap full → nothing more
        $credit->creditPhase($third->id);

        $sessions = FocusSession::query()->orderBy('id')->get();
        $this->assertCount(2, $sessions);
        $this->assertSame(5, $sessions[0]->minutes);
        $this->assertSame(15, $sessions[1]->minutes);

        $daily = UserDailyFocus::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(720, $daily->minutes);
        $this->assertSame(5, $daily->sessions);
    }

    public function test_local_dates_and_the_daily_bucket_follow_the_users_timezone(): void
    {
        // 23:30 UTC is already 12:30 the next day in Auckland (UTC+13).
        Carbon::setTestNow(Carbon::parse('2026-11-06 23:30:00'));

        $user = User::factory()->create(['timezone' => 'Pacific/Auckland']);
        $room = $this->makeRoom($user);

        $phase = $this->focusPhase($room, '2026-11-06 23:00:00', '2026-11-06 23:30:00');
        $this->attend($phase, $user, 1800); // 30 minutes

        app(FocusCreditService::class)->creditPhase($phase->id);

        $session = FocusSession::query()->firstOrFail();
        $this->assertSame('2026-11-07', $session->local_date);

        $daily = UserDailyFocus::query()->firstOrFail();
        $this->assertSame('2026-11-07', $daily->date);
        $this->assertSame(30, $daily->minutes);

        // 30 ≥ 25 → the streak starts on the user's local today.
        $this->assertSame(1, (int) $user->fresh()->current_streak);

        $this->actingAs($user)
            ->getJson('/api/v1/me/stats')
            ->assertOk()
            ->assertJsonPath('today_minutes', 30)
            ->assertJsonPath('all_time_minutes', 30);
    }

    /* ----------------------------------------------------------------------
     * Streaks (PRD 4.8: a day counts at 25+ credited minutes)
     * ---------------------------------------------------------------------- */

    public function test_streaks_count_qualifying_days_and_survive_until_the_day_after(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $room = $this->makeRoom($user);
        $credit = app(FocusCreditService::class);

        $qualifyingDay = function (string $day) use ($room, $user, $credit): void {
            $phase = $this->focusPhase($room, "{$day} 09:00:00", "{$day} 09:25:00");
            $this->attend($phase, $user, 1500); // exactly 25 minutes

            Carbon::setTestNow(Carbon::parse("{$day} 09:30:00"));
            $credit->creditPhase($phase->id);
        };

        $qualifyingDay('2026-11-02');
        $this->assertSame(1, (int) $user->fresh()->current_streak);

        $qualifyingDay('2026-11-03'); // consecutive → 2
        $this->assertSame(2, (int) $user->fresh()->current_streak);

        $qualifyingDay('2026-11-05'); // 11-04 missed → restarts at 1, longest stays 2
        $user->refresh();
        $this->assertSame(1, (int) $user->current_streak);
        $this->assertSame(2, (int) $user->longest_streak);
        $this->assertSame('2026-11-05', $user->last_focus_date->toDateString());

        // The streak is still alive the day after the last qualifying day…
        Carbon::setTestNow(Carbon::parse('2026-11-06 08:00:00'));
        $this->actingAs($user)
            ->getJson('/api/v1/me/stats')
            ->assertOk()
            ->assertJsonPath('current_streak', 1);

        // …and dead once two days pass with no qualifying day.
        Carbon::setTestNow(Carbon::parse('2026-11-07 08:00:00'));
        $this->actingAs($user)
            ->getJson('/api/v1/me/stats')
            ->assertOk()
            ->assertJsonPath('current_streak', 0)
            ->assertJsonPath('longest_streak', 2);
    }

    public function test_a_stale_streak_reads_as_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 12:00:00'));

        $user = User::factory()->create([
            'current_streak' => 9,
            'longest_streak' => 12,
            'last_focus_date' => '2026-11-01', // five days ago: nothing has run since
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/me/stats')
            ->assertOk()
            ->assertJsonPath('current_streak', 0)
            ->assertJsonPath('longest_streak', 12);
    }

    /* ----------------------------------------------------------------------
     * The credit broadcast (PRD 5: toast + check-in prompt)
     * ---------------------------------------------------------------------- */

    public function test_crediting_broadcasts_on_the_members_private_channel(): void
    {
        Event::fake([FocusCredited::class]);
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00'));

        $user = User::factory()->create();
        $room = $this->makeRoom($user);

        $phase = $this->focusPhase($room, '2026-11-06 09:30:00', '2026-11-06 09:55:00');
        $this->attend($phase, $user, 600);

        app(FocusCreditService::class)->creditPhase($phase->id);

        Event::assertDispatched(FocusCredited::class, function (FocusCredited $event) use ($user) {
            return $event->userId === (int) $user->id
                && $event->minutes === 10
                && $event->broadcastOn() === ['private-user.'.$user->id]
                && $event->broadcastWith()['session_id'] === $event->sessionId;
        });
    }

    /* ----------------------------------------------------------------------
     * Phase-end wiring
     * ---------------------------------------------------------------------- */

    public function test_a_shortened_session_credits_expected_minutes_and_shows_in_stats(): void
    {
        // PRD M5 "done when": a shortened session credits the expected minutes
        // and they show up in stats. A 6-minute focus block heartbeated every
        // 30 s earns 5 min 30 s present → floor → 5 credited minutes.
        Carbon::setTestNow(Carbon::parse('2026-11-06 09:00:00'));

        $host = User::factory()->create();
        $room = $this->makeRoom($host, ['focus_minutes' => 6]);

        app(RoomClockService::class)->transition($room, 'start');
        $room->refresh();
        $this->join($room, $host);

        $presence = app(PresenceService::class);

        for ($i = 1; $i <= 11; $i++) {
            Carbon::setTestNow(Carbon::parse('2026-11-06 09:00:00')->addSeconds(30 * $i));
            $presence->heartbeat($room, $host, true);
        }

        $phase = RoomPhase::query()->where('room_id', $room->id)->where('type', 'focus')->firstOrFail();
        $attendance = PhaseAttendance::query()
            ->where('phase_id', $phase->id)
            ->where('user_id', $host->id)
            ->firstOrFail();

        $this->assertSame(330, $attendance->seconds_present); // 11 beats × 30 s, all inside the window

        // The next beat lands after the phase ended: the lazy advance flips
        // the room to its break and credits the focus window on the way past.
        Carbon::setTestNow(Carbon::parse('2026-11-06 09:07:00'));
        $presence->heartbeat($room, $host, true);

        $room->refresh();
        $this->assertSame('short_break', $room->phase);

        $session = FocusSession::query()->where('user_id', $host->id)->firstOrFail();
        $this->assertSame(5, $session->minutes); // floor(330 / 60)
        $this->assertSame('2026-11-06', $session->local_date);
        $this->assertNotNull($phase->fresh()->credited_at);

        $this->actingAs($host)
            ->getJson('/api/v1/me/stats')
            ->assertOk()
            ->assertJsonPath('today_minutes', 5)
            ->assertJsonPath('week_minutes', 5)
            ->assertJsonPath('all_time_minutes', 5)
            ->assertJsonPath('sessions_total', 1)
            ->assertJsonPath('avg_session_minutes', 5) // json_encode(5.0) === "5"
            ->assertJsonPath('current_streak', 0) // 5 < 25 → no streak yet
            ->assertJsonPath('best_day.date', '2026-11-06')
            ->assertJsonPath('best_day.minutes', 5);

        $days = $this->actingAs($host)
            ->getJson('/api/v1/me/heatmap')
            ->assertOk()
            ->json('days');

        $last = end($days);
        $this->assertSame('2026-11-06', $last['date']);
        $this->assertSame(5, $last['minutes']);
    }

    public function test_a_host_skip_credits_the_interrupted_focus_phase_immediately(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00'));

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $clock = app(RoomClockService::class);

        $clock->transition($room, 'start');
        $room->refresh();
        $this->join($room, $host);

        $phase = RoomPhase::query()->where('room_id', $room->id)->where('type', 'focus')->firstOrFail();
        $this->attend($phase, $host, 600); // 10 minutes of proven presence

        Carbon::setTestNow(Carbon::parse('2026-11-06 10:10:00'));
        $clock->transition($room, 'skip');

        $phase->refresh();
        $this->assertNotNull($phase->credited_at);
        // Closed at the skip, not at the window's original end.
        $this->assertSame('2026-11-06 10:10:00', $phase->ends_at->format('Y-m-d H:i:s'));

        $session = FocusSession::query()->where('user_id', $host->id)->firstOrFail();
        $this->assertSame(10, $session->minutes);
        $this->assertSame('2026-11-06', $session->local_date);
    }

    public function test_always_on_phase_flips_credit_the_ended_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00'));

        $watcher = User::factory()->create();
        $room = Room::create([
            'name' => 'Always On',
            'type' => Room::TYPE_ALWAYS_ON,
            'focus_minutes' => 25,
            'short_break_minutes' => 5,
            'long_break_minutes' => 15,
            'long_break_every' => 4,
            'max_members' => 100,
            'last_active_at' => now(),
            // The first focus window ends in 10 seconds.
            'anchor_epoch' => now()->timestamp - (25 * 60 - 10),
        ]);

        // First sweep: records the marker, but the only "previous" window it
        // can compute is the still-running first one — nothing to credit yet.
        $this->artisan('rooms:broadcast-always-on')->assertSuccessful();
        $this->assertSame(0, RoomPhase::query()->count());

        // A mid-focus heartbeat creates the window row (presence proof).
        $this->join($room, $watcher);
        $this->beat($room, $watcher);

        $phase = RoomPhase::query()->where('room_id', $room->id)->firstOrFail();
        $this->assertSame('focus', $phase->type);
        $this->attend($phase, $watcher, 1500);

        // Cross the boundary: the sweep sees the flip and credits the window.
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:20'));
        $this->artisan('rooms:broadcast-always-on')->assertSuccessful();

        $this->assertNotNull($phase->fresh()->credited_at);

        $session = FocusSession::query()->where('user_id', $watcher->id)->firstOrFail();
        $this->assertSame(25, $session->minutes);
        $this->assertSame(25, UserDailyFocus::query()->where('user_id', $watcher->id)->firstOrFail()->minutes);
        $this->assertSame(1, (int) $watcher->fresh()->current_streak);
    }

    /* ----------------------------------------------------------------------
     * Stats endpoints (PRD 6 / 4.8)
     * ---------------------------------------------------------------------- */

    public function test_stats_report_totals_streaks_and_checkin_rate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 12:00:00')); // Friday; week began 2026-11-02

        $user = User::factory()->create([
            'timezone' => 'UTC',
            'current_streak' => 4,
            'longest_streak' => 6,
            'last_focus_date' => '2026-11-05', // yesterday → the run is alive
        ]);
        $room = $this->makeRoom($user);

        UserDailyFocus::create(['user_id' => $user->id, 'date' => '2026-11-06', 'minutes' => 50, 'sessions' => 2]);
        UserDailyFocus::create(['user_id' => $user->id, 'date' => '2026-11-05', 'minutes' => 30, 'sessions' => 1]);
        UserDailyFocus::create(['user_id' => $user->id, 'date' => '2026-11-01', 'minutes' => 40, 'sessions' => 1]); // last week

        $morning = $this->focusPhase($room, '2026-11-06 09:30:00', '2026-11-06 09:55:00');
        $yesterday = $this->focusPhase($room, '2026-11-05 09:00:00', '2026-11-05 09:10:00');
        $afternoon = $this->focusPhase($room, '2026-11-06 14:00:00', '2026-11-06 14:05:00');

        FocusSession::create(['user_id' => $user->id, 'room_id' => $room->id, 'phase_id' => $morning->id, 'minutes' => 20, 'outcome' => 'done', 'local_date' => '2026-11-06']);
        FocusSession::create(['user_id' => $user->id, 'room_id' => $room->id, 'phase_id' => $yesterday->id, 'minutes' => 10, 'outcome' => 'no', 'local_date' => '2026-11-05']);
        FocusSession::create(['user_id' => $user->id, 'room_id' => $room->id, 'phase_id' => $afternoon->id, 'minutes' => 5, 'local_date' => '2026-11-06']);

        $this->actingAs($user)
            ->getJson('/api/v1/me/stats')
            ->assertOk()
            ->assertJsonPath('today_minutes', 50)
            ->assertJsonPath('week_minutes', 80) // 50 + 30; 11-01 is last week
            ->assertJsonPath('all_time_minutes', 120)
            ->assertJsonPath('current_streak', 4)
            ->assertJsonPath('longest_streak', 6)
            ->assertJsonPath('sessions_total', 3)
            ->assertJsonPath('avg_session_minutes', 11.7) // 35 / 3
            ->assertJsonPath('best_day.date', '2026-11-06')
            ->assertJsonPath('best_day.minutes', 50)
            ->assertJsonPath('favorite_hours.0.hour', 9)
            ->assertJsonPath('favorite_hours.0.minutes', 30) // 20 + 10 across both mornings
            ->assertJsonPath('favorite_hours.1.hour', 14)
            ->assertJsonPath('favorite_hours.1.minutes', 5)
            ->assertJsonPath('checkins.answered', 2)
            ->assertJsonPath('checkins.done', 1)
            ->assertJsonPath('checkins.completion_rate', 0.5);
    }

    public function test_the_heatmap_covers_the_last_twelve_weeks_of_local_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 12:00:00'));

        $user = User::factory()->create(['timezone' => 'UTC']);

        UserDailyFocus::create(['user_id' => $user->id, 'date' => '2026-11-05', 'minutes' => 30, 'sessions' => 1]);
        UserDailyFocus::create(['user_id' => $user->id, 'date' => '2026-11-06', 'minutes' => 20, 'sessions' => 1]);
        UserDailyFocus::create(['user_id' => $user->id, 'date' => '2026-08-01', 'minutes' => 100, 'sessions' => 2]); // too old

        $response = $this->actingAs($user)->getJson('/api/v1/me/heatmap')->assertOk();

        $response->assertJsonPath('start_date', '2026-08-17') // Monday, 11 weeks before this week's Monday
            ->assertJsonPath('end_date', '2026-11-06')
            ->assertJsonPath('weeks', 12)
            ->assertJsonPath('max_minutes', 30)
            ->assertJsonPath('days.0.date', '2026-08-17')
            ->assertJsonPath('days.0.minutes', 0)
            ->assertJsonPath('days.80.date', '2026-11-05')
            ->assertJsonPath('days.80.minutes', 30)
            ->assertJsonPath('days.81.date', '2026-11-06')
            ->assertJsonPath('days.81.minutes', 20);

        // 2026-08-17 .. 2026-11-06 inclusive — a whole 12 week columns with
        // the current (partial) week last; the client pads the rest.
        $this->assertCount(82, $response->json('days'));
    }

    public function test_checkin_outcome_requires_ownership_and_valid_values(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00'));

        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $room = $this->makeRoom($user);

        $session = FocusSession::create(['user_id' => $user->id, 'room_id' => $room->id, 'minutes' => 25, 'local_date' => '2026-11-06']);
        $theirs = FocusSession::create(['user_id' => $stranger->id, 'room_id' => $room->id, 'minutes' => 25, 'local_date' => '2026-11-06']);

        $this->actingAs($user)
            ->patchJson("/api/v1/me/sessions/{$session->id}/outcome", ['outcome' => 'done'])
            ->assertOk()
            ->assertJsonPath('session.outcome', 'done');

        $this->assertSame('done', $session->fresh()->outcome);

        // Someone else's session must look exactly like a missing one.
        $this->actingAs($user)
            ->patchJson("/api/v1/me/sessions/{$theirs->id}/outcome", ['outcome' => 'done'])
            ->assertNotFound();

        $this->actingAs($user)
            ->patchJson("/api/v1/me/sessions/{$session->id}/outcome", ['outcome' => 'finished'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['outcome']);

        $this->actingAs($user)
            ->patchJson("/api/v1/me/sessions/{$session->id}/outcome", [])
            ->assertUnprocessable();
    }

    /* ----------------------------------------------------------------------
     * Leaderboard (PRD 4.8: weekly, opt-in, "Anonymous focuser")
     * ---------------------------------------------------------------------- */

    public function test_weekly_leaderboard_ranks_members_and_anonymizes_opt_outs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 12:00:00'));

        $alice = User::factory()->create(['name' => 'Alice', 'show_on_leaderboard' => true]);
        $bob = User::factory()->create(['name' => 'Bob', 'show_on_leaderboard' => false]);
        $dave = User::factory()->create(['name' => 'Dave', 'show_on_leaderboard' => true]);
        $carol = User::factory()->create(['name' => 'Carol', 'is_suspended' => true]);

        $room = $this->makeRoom($alice);
        $other = $this->makeRoom($alice);

        $seed = fn (User $user, int $minutes, ?Room $room, string $date = '2026-11-03') => FocusSession::create([
            'user_id' => $user->id,
            'room_id' => $room?->id,
            'minutes' => $minutes,
            'local_date' => $date,
        ]);

        $seed($alice, 100, $room);
        $seed($alice, 30, $other, '2026-11-04'); // 130 this week
        $seed($bob, 90, $room);
        $seed($dave, 90, $room);
        $seed($carol, 500, $room); // suspended → never listed
        $seed($alice, 400, $room, '2026-10-30'); // last week → not counted

        $response = $this->actingAs($alice)->getJson('/api/v1/leaderboard')->assertOk();

        $response->assertJsonPath('scope', 'weekly')
            ->assertJsonPath('week_start', '2026-11-02');

        $entries = $response->json('entries');
        $this->assertCount(3, $entries);

        $this->assertSame(1, $entries[0]['rank']);
        $this->assertSame('Alice', $entries[0]['name']);
        $this->assertSame(130, $entries[0]['minutes']);
        $this->assertTrue($entries[0]['is_me']);
        $this->assertFalse($entries[0]['anonymous']);

        // Opted out: still ranks (the minutes are theirs) but identity is
        // withheld — PRD 4.8's "Anonymous focuser".
        $this->assertSame(2, $entries[1]['rank']);
        $this->assertSame('Anonymous focuser', $entries[1]['name']);
        $this->assertTrue($entries[1]['anonymous']);
        $this->assertFalse($entries[1]['is_me']);
        $this->assertNull($entries[1]['user_id']);
        $this->assertNull($entries[1]['avatar_emoji']);

        // Bob's 90 ties Dave's 90 → lower id first.
        $this->assertSame('Dave', $entries[2]['name']);
        $this->assertSame(90, $entries[2]['minutes']);

        // PRD 6 lists /leaderboard as a public page: guests read the same top.
        $this->getJson('/api/v1/leaderboard')
            ->assertOk()
            ->assertJsonCount(3, 'entries');
    }

    public function test_leaderboard_scopes_to_a_room_and_validates_query_input(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 12:00:00'));

        $alice = User::factory()->create();
        $here = $this->makeRoom($alice, ['name' => 'Here']);
        $there = $this->makeRoom($alice, ['name' => 'There']);

        FocusSession::create(['user_id' => $alice->id, 'room_id' => $here->id, 'minutes' => 50, 'local_date' => '2026-11-03']);
        FocusSession::create(['user_id' => $alice->id, 'room_id' => $there->id, 'minutes' => 70, 'local_date' => '2026-11-03']);

        $this->actingAs($alice)
            ->getJson("/api/v1/leaderboard?room={$here->id}")
            ->assertOk()
            ->assertJsonPath('room_id', $here->id)
            ->assertJsonPath('entries.0.minutes', 50);

        $this->actingAs($alice)
            ->getJson('/api/v1/leaderboard?room=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room']);

        $this->actingAs($alice)
            ->getJson('/api/v1/leaderboard?scope=daily')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scope']);
    }

    /* ----------------------------------------------------------------------
     * Nightly maintenance (PRD 7.3)
     * ---------------------------------------------------------------------- */

    public function test_nightly_rollup_rebuilds_daily_rows_and_repairs_stalled_credits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 12:00:00'));

        $user = User::factory()->create();
        $orphan = User::factory()->create();
        $room = $this->makeRoom($user);

        // A deliberately wrong daily row for a user who does have a session.
        $planted = $this->focusPhase($room, '2026-11-06 08:00:00', '2026-11-06 08:10:00');
        FocusSession::create(['user_id' => $user->id, 'room_id' => $room->id, 'phase_id' => $planted->id, 'minutes' => 10, 'local_date' => '2026-11-06']);
        UserDailyFocus::create(['user_id' => $user->id, 'date' => '2026-11-06', 'minutes' => 111, 'sessions' => 9]);

        // A daily row no session backs at all.
        UserDailyFocus::create(['user_id' => $orphan->id, 'date' => '2026-11-06', 'minutes' => 77, 'sessions' => 1]);

        // A stalled credit: the phase ended two hours ago, its job was lost.
        $stalled = $this->focusPhase($room, '2026-11-06 09:30:00', '2026-11-06 09:55:00');
        $this->attend($stalled, $user, 600);

        $this->artisan('focus:rollup')->assertSuccessful();

        $this->assertNotNull($stalled->fresh()->credited_at);
        $this->assertSame(2, FocusSession::query()->where('user_id', $user->id)->count());

        $rebuilt = UserDailyFocus::query()->where('user_id', $user->id)->where('date', '2026-11-06')->firstOrFail();
        $this->assertSame(20, $rebuilt->minutes); // 10 planted + 10 repaired
        $this->assertSame(2, $rebuilt->sessions);

        $this->assertNull(UserDailyFocus::query()->where('user_id', $orphan->id)->first());
    }

    public function test_nightly_prune_keeps_recent_and_uncredited_attendance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 12:00:00'));

        $user = User::factory()->create();
        $room = $this->makeRoom($user);

        $old = $this->focusPhase($room, '2026-09-27 08:00:00', '2026-09-27 08:25:00');
        $old->update(['credited_at' => '2026-09-27 09:00:00']);

        $recent = $this->focusPhase($room, '2026-11-01 08:00:00', '2026-11-01 08:25:00');
        $recent->update(['credited_at' => '2026-11-01 09:00:00']);

        $stalled = $this->focusPhase($room, '2026-09-27 09:30:00', '2026-09-27 09:55:00'); // never credited

        $ancient = $this->attend($old, $user, 600);
        $fresh = $this->attend($recent, $user, 600);
        $pending = $this->attend($stalled, $user, 600);

        $this->artisan('focus:prune-attendance')->assertSuccessful();

        // Attendance only feeds the credit job: dead weight goes, anything
        // still needed (recent, or never credited) stays.
        $this->assertNull(PhaseAttendance::query()->find($ancient->id));
        $this->assertNotNull(PhaseAttendance::query()->find($fresh->id));
        $this->assertNotNull(PhaseAttendance::query()->find($pending->id));
    }
}

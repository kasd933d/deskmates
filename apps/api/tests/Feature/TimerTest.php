<?php

namespace Tests\Feature;

use App\Events\RoomPhaseChanged;
use App\Jobs\AdvancePhase;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use App\Services\RoomClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TimerTest extends TestCase
{
    use RefreshDatabase;

    private function makeRoom(User $host, array $overrides = []): Room
    {
        return Room::create(array_merge([
            'name' => 'Timer Room',
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

    public function test_host_can_start_the_timer(): void
    {
        Event::fake([RoomPhaseChanged::class]);
        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        $response = $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/timer/start");

        $response->assertOk()
            ->assertJsonPath('timer.phase', 'focus')
            ->assertJsonPath('timer.cycle_index', 0)
            ->assertJsonPath('timer.phase_version', 1);

        $this->assertNotNull($response->json('timer.phase_ends_at_ms'));

        // phase_ends_at stores second precision, so the remaining time can be
        // up to 999 ms short of exactly 25 minutes.
        $remaining = $response->json('timer.phase_ends_at_ms') - $response->json('server_now_ms');
        $this->assertGreaterThan(25 * 60 * 1000 - 1000, $remaining);
        $this->assertLessThanOrEqual(25 * 60 * 1000, $remaining);

        Event::assertDispatched(RoomPhaseChanged::class, fn (RoomPhaseChanged $e) => $e->roomId === $room->id);
    }

    public function test_start_queues_the_delayed_advance_job(): void
    {
        Queue::fake();
        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/start");

        Queue::assertPushed(AdvancePhase::class, fn (AdvancePhase $job) => $job->roomId === $room->id);
    }

    public function test_member_cannot_control_the_timer(): void
    {
        $host = User::factory()->create();
        $member = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $member);

        $this->actingAs($member)
            ->postJson("/api/v1/rooms/{$room->id}/timer/start")
            ->assertStatus(403);

        $this->assertSame('idle', $room->fresh()->phase);
    }

    public function test_always_on_room_timer_cannot_be_controlled_even_by_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $room = $this->makeRoom($admin, [
            'type' => Room::TYPE_ALWAYS_ON,
            'host_id' => null,
            'anchor_epoch' => 1735689600,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/rooms/{$room->id}/timer/start")
            ->assertStatus(403);

        $this->actingAs($admin)
            ->postJson("/api/v1/rooms/{$room->id}/timer/skip")
            ->assertStatus(403);
    }

    public function test_skip_advances_focus_to_short_break_and_back(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/start")
            ->assertJsonPath('timer.phase', 'focus');

        // Skip out of focus -> short break (cycle 0 of 4 -> not a long break).
        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/skip")
            ->assertOk()
            ->assertJsonPath('timer.phase', 'short_break')
            ->assertJsonPath('timer.phase_version', 2);

        // Skip out of the break -> focus, cycle increments.
        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/skip")
            ->assertOk()
            ->assertJsonPath('timer.phase', 'focus')
            ->assertJsonPath('timer.cycle_index', 1);
    }

    public function test_long_break_appears_after_nth_cycle(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/start");

        // Each cycle: skip focus -> break, skip break -> next focus.
        // After 3 full cycles we sit in cycle 3's focus.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/skip");
            $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/skip");
        }

        $this->assertSame('focus', $room->fresh()->phase);
        $this->assertSame(3, $room->fresh()->cycle_index);

        // Cycle 3 (the 4th): skipping its focus must produce the LONG break
        // because (3 + 1) % 4 === 0.
        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/skip")
            ->assertJsonPath('timer.phase', 'long_break')
            ->assertJsonPath('timer.cycle_index', 3);
    }

    public function test_pause_stops_the_timer_and_start_resumes(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/start");
        $paused = $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/pause");

        $paused->assertOk()
            ->assertJsonPath('timer.phase', 'idle')
            ->assertJsonPath('timer.phase_ends_at_ms', null);

        // Start again resumes into focus with a fresh phase version.
        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/start")
            ->assertOk()
            ->assertJsonPath('timer.phase', 'focus')
            ->assertJsonPath('timer.phase_version', 3);
    }

    public function test_reset_returns_to_idle_and_zeroes_the_cycle(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/start");
        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/skip");

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/reset")
            ->assertOk()
            ->assertJsonPath('timer.phase', 'idle')
            ->assertJsonPath('timer.cycle_index', 0)
            ->assertJsonPath('timer.phase_ends_at_ms', null);
    }

    public function test_advance_phase_job_is_idempotent_after_skip(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/start");
        $version = $room->fresh()->phase_version;

        // Host skips, bumping the version past the queued job's expectation.
        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/skip");
        $afterSkip = $room->fresh();

        // The stale job must not advance again.
        (new AdvancePhase($room->id, $version))->handle(app(RoomClockService::class));

        $fresh = $room->fresh();
        $this->assertSame($afterSkip->phase, $fresh->phase);
        $this->assertSame($afterSkip->phase_version, $fresh->phase_version);
    }

    public function test_lazy_advance_fires_when_a_phase_expired_unattended(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/start");

        // Simulate the phase ending long ago with no job having run.
        $room->forceFill([
            'phase_ends_at' => now()->subMinutes(5),
            'phase_version' => 99,
        ])->save();

        $response = $this->actingAs($host)->getJson("/api/v1/rooms/{$room->slug}");

        $response->assertOk()->assertJsonPath('timer.phase', 'short_break');

        // And the version moved past the expired phase.
        $this->assertGreaterThan(99, $room->fresh()->phase_version);
    }

    public function test_show_returns_deterministic_timer_for_always_on_rooms(): void
    {
        $user = User::factory()->create();
        $room = $this->makeRoom($user, [
            'type' => Room::TYPE_ALWAYS_ON,
            'host_id' => null,
            'anchor_epoch' => 1735689600,
        ]);
        $this->join($room, $user);

        $response = $this->actingAs($user)->getJson("/api/v1/rooms/{$room->slug}");

        $response->assertOk()
            ->assertJsonStructure(['timer' => ['phase', 'phase_ends_at_ms', 'phase_started_at_ms', 'cycle_index']]);

        // Two fetches a second apart return the same phase end (deterministic).
        $first = $response->json('timer.phase_ends_at_ms');
        $again = $this->actingAs($user)->getJson("/api/v1/rooms/{$room->slug}")
            ->json('timer.phase_ends_at_ms');
        $this->assertSame($first, $again);
    }

    public function test_two_rapid_skips_each_advance_once_sequentially(): void
    {
        // True parallelism needs a real DB lock; with the sqlite test driver
        // we verify the semantics that matter: each authorized skip advances
        // exactly one phase and bumps the version exactly once.
        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/start");

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/skip");
        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/skip");

        $fresh = $room->fresh();
        // start -> focus, skip -> short break, skip -> cycle 1 focus.
        $this->assertSame('focus', $fresh->phase);
        $this->assertSame(1, $fresh->cycle_index);
        $this->assertSame(3, $fresh->phase_version); // start + 2 skips
    }

    public function test_unknown_timer_action_is_rejected(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/timer/explode")
            ->assertStatus(404); // route whereIn constraint
    }

    public function test_guest_cannot_control_the_timer(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        $this->postJson("/api/v1/rooms/{$room->id}/timer/start")->assertStatus(401);
    }
}

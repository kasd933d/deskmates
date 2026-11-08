<?php

namespace Tests\Feature;

use App\Events\RoomSettingsUpdated;
use App\Models\PushSubscription;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\ScheduledSession;
use App\Models\ScheduledSessionRsvp;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Milestone 6 (PRD 4.11/4.10): the /schedule API — scoping, booking,
 * cancelling, RSVP — plus the two every-minute sweeps: auto-starting a
 * booked session (config applied, timer running) and the 10-minute push
 * reminder that fires exactly once per session.
 */
class ScheduledSessionTest extends TestCase
{
    use RefreshDatabase;

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

    private function makeSession(Room $room, User $host, array $overrides = []): ScheduledSession
    {
        return ScheduledSession::create(array_merge([
            'room_id' => $room->id,
            'host_id' => $host->id,
            'title' => 'Morning sprint',
            'starts_at' => now()->addHour(),
            'config' => [
                'focus_minutes' => 25,
                'short_break_minutes' => 5,
                'long_break_minutes' => 15,
                'long_break_every' => 4,
            ],
        ], $overrides));
    }

    /*
    |----------------------------------------------------------------------
    | List scoping
    |----------------------------------------------------------------------
    */

    public function test_list_shows_upcoming_public_and_member_sessions_only(): void
    {
        $publicHost = User::factory()->create();
        $privateHost = User::factory()->create();
        $publicRoom = $this->makeRoom($publicHost);
        $privateRoom = $this->makeRoom($privateHost, [
            'type' => Room::TYPE_PRIVATE,
            'invite_code' => 'SECRET12',
        ]);

        $visible = $this->makeSession($publicRoom, $publicHost);
        $this->makeSession($privateRoom, $privateHost);
        // Hidden: past, cancelled, and archived-room bookings.
        $this->makeSession($publicRoom, $publicHost, ['starts_at' => now()->subMinutes(5)]);
        $this->makeSession($publicRoom, $publicHost, ['status' => ScheduledSession::STATUS_CANCELLED]);
        $archived = $this->makeRoom($publicHost, ['archived_at' => now()]);
        $this->makeSession($archived, $publicHost);

        $stranger = User::factory()->create();
        $response = $this->actingAs($stranger)->getJson('/api/v1/sessions');

        $response->assertOk()
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.id', $visible->id);
        $this->assertArrayHasKey('server_now_ms', $response->json());

        // A member of the private room gains visibility into it.
        $member = User::factory()->create();
        $this->join($privateRoom, $member);
        $this->actingAs($member)->getJson('/api/v1/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'sessions');
    }

    public function test_guest_cannot_list_sessions(): void
    {
        $this->getJson('/api/v1/sessions')->assertStatus(401);
    }

    /*
    |----------------------------------------------------------------------
    | Booking
    |----------------------------------------------------------------------
    */

    public function test_only_room_host_can_create_a_session(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $stranger = User::factory()->create();

        $payload = [
            'room_id' => $room->id,
            'title' => 'Exam grind',
            'starts_at' => now()->addHours(2)->toIso8601String(),
        ];

        $this->actingAs($stranger)->postJson('/api/v1/sessions', $payload)
            ->assertStatus(403);

        $response = $this->actingAs($host)->postJson('/api/v1/sessions', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('session.title', 'Exam grind')
            ->assertJsonPath('session.status', ScheduledSession::STATUS_SCHEDULED)
            ->assertJsonPath('session.room.slug', $room->slug)
            // Config defaults to the room's current timer (PRD 4.11).
            ->assertJsonPath('session.config.focus_minutes', 25)
            ->assertJsonPath('session.config.long_break_every', 4);
    }

    public function test_create_rejects_always_on_and_archived_rooms(): void
    {
        $host = User::factory()->create();
        $alwaysOn = Room::create([
            'slug' => 'the-library',
            'name' => 'The Library',
            'type' => Room::TYPE_ALWAYS_ON,
            'anchor_epoch' => 1735689600,
        ]);
        $payload = [
            'room_id' => $alwaysOn->id,
            'title' => 'Exam grind',
            'starts_at' => now()->addHours(2)->toIso8601String(),
        ];
        $this->actingAs($host)->postJson('/api/v1/sessions', $payload)
            ->assertStatus(403);

        $archived = $this->makeRoom($host, ['archived_at' => now()]);
        $this->actingAs($host)->postJson('/api/v1/sessions', array_merge($payload, ['room_id' => $archived->id]))
            ->assertStatus(422);
    }

    public function test_create_rejects_past_start_times_and_stores_explicit_config(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        $this->actingAs($host)->postJson('/api/v1/sessions', [
            'room_id' => $room->id,
            'title' => 'Too late',
            'starts_at' => now()->subMinute()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('starts_at');

        $this->actingAs($host)->postJson('/api/v1/sessions', [
            'room_id' => $room->id,
            'title' => 'Deep work',
            'starts_at' => now()->addHours(3)->toIso8601String(),
            'focus_minutes' => 50,
            'short_break_minutes' => 10,
            'long_break_minutes' => 20,
            'long_break_every' => 3,
        ])->assertStatus(201)
            ->assertJsonPath('session.config.focus_minutes', 50)
            ->assertJsonPath('session.config.short_break_minutes', 10)
            ->assertJsonPath('session.config.long_break_minutes', 20)
            ->assertJsonPath('session.config.long_break_every', 3);
    }

    /*
    |----------------------------------------------------------------------
    | Cancelling
    |----------------------------------------------------------------------
    */

    public function test_session_host_room_host_or_admin_can_cancel(): void
    {
        $roomHost = User::factory()->create();
        $room = $this->makeRoom($roomHost);
        $bookingHost = User::factory()->create();

        $byBookingHost = $this->makeSession($room, $bookingHost);
        $byRoomHost = $this->makeSession($room, $bookingHost);
        $byAdmin = $this->makeSession($room, $bookingHost);

        $this->actingAs($bookingHost)
            ->deleteJson("/api/v1/sessions/{$byBookingHost->id}")
            ->assertOk()
            ->assertJsonPath('session.status', ScheduledSession::STATUS_CANCELLED);

        $this->actingAs($roomHost)
            ->deleteJson("/api/v1/sessions/{$byRoomHost->id}")
            ->assertOk();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->deleteJson("/api/v1/sessions/{$byAdmin->id}")
            ->assertOk();
    }

    public function test_stranger_cannot_cancel_and_started_sessions_are_locked(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $session = $this->makeSession($room, $host);
        $started = $this->makeSession($room, $host, ['status' => ScheduledSession::STATUS_STARTED]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->deleteJson("/api/v1/sessions/{$session->id}")
            ->assertStatus(403);

        $this->actingAs($host)
            ->deleteJson("/api/v1/sessions/{$started->id}")
            ->assertStatus(422);

        $this->assertSame(ScheduledSession::STATUS_SCHEDULED, $session->fresh()->status);
    }

    /*
    |----------------------------------------------------------------------
    | RSVP
    |----------------------------------------------------------------------
    */

    public function test_rsvp_is_idempotent_and_reports_state(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $session = $this->makeSession($room, $host);
        $mate = User::factory()->create();

        foreach (range(1, 2) as $attempt) {
            $this->actingAs($mate)
                ->postJson("/api/v1/sessions/{$session->id}/rsvp")
                ->assertOk()
                ->assertJsonPath('session.has_rsvp', true)
                ->assertJsonPath('session.rsvp_count', 1);
        }

        $this->assertDatabaseCount('scheduled_session_rsvps', 1);

        $this->actingAs($mate)
            ->deleteJson("/api/v1/sessions/{$session->id}/rsvp")
            ->assertOk()
            ->assertJsonPath('session.has_rsvp', false)
            ->assertJsonPath('session.rsvp_count', 0);

        // Cancelling twice is a no-op, not an error.
        $this->actingAs($mate)
            ->deleteJson("/api/v1/sessions/{$session->id}/rsvp")
            ->assertOk();
    }

    public function test_rsvp_requires_sight_into_private_rooms(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host, ['type' => Room::TYPE_PRIVATE, 'invite_code' => 'SECRET12']);
        $session = $this->makeSession($room, $host);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson("/api/v1/sessions/{$session->id}/rsvp")
            ->assertStatus(403);

        $member = User::factory()->create();
        $this->join($room, $member);
        $this->actingAs($member)
            ->postJson("/api/v1/sessions/{$session->id}/rsvp")
            ->assertOk()
            ->assertJsonPath('session.has_rsvp', true);
    }

    public function test_rsvp_rejects_past_and_cancelled_sessions(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $mate = User::factory()->create();

        $past = $this->makeSession($room, $host, ['starts_at' => now()->subMinute()]);
        $this->actingAs($mate)
            ->postJson("/api/v1/sessions/{$past->id}/rsvp")
            ->assertStatus(422);

        $cancelled = $this->makeSession($room, $host, ['status' => ScheduledSession::STATUS_CANCELLED]);
        $this->actingAs($mate)
            ->postJson("/api/v1/sessions/{$cancelled->id}/rsvp")
            ->assertStatus(422);
    }

    /*
    |----------------------------------------------------------------------
    | sessions:start — auto-start (PRD 4.11)
    |----------------------------------------------------------------------
    */

    public function test_due_session_starts_the_room_timer_with_its_config(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $session = $this->makeSession($room, $host, [
            'starts_at' => now()->subMinute(),
            'config' => [
                'focus_minutes' => 50,
                'short_break_minutes' => 10,
                'long_break_minutes' => 20,
                'long_break_every' => 3,
            ],
        ]);

        Event::fake([RoomSettingsUpdated::class]);

        $this->artisan('sessions:start')->assertSuccessful();

        $room->refresh();
        $session->refresh();

        $this->assertSame(ScheduledSession::STATUS_STARTED, $session->status);
        $this->assertSame('focus', $room->phase);
        $this->assertSame(50, $room->focus_minutes);
        $this->assertSame(10, $room->short_break_minutes);
        $this->assertSame(20, $room->long_break_minutes);
        $this->assertSame(3, $room->long_break_every);
        $this->assertTrue($room->phase_ends_at->greaterThan(now()->addMinutes(49)));

        // The room's clients are told about the applied config.
        Event::assertDispatched(RoomSettingsUpdated::class, fn (RoomSettingsUpdated $event): bool => $event->roomId === $room->id
            && $event->config['focus_minutes'] === 50);

        // Re-running never double-starts: the status flip is the claim.
        $version = $room->phase_version;
        $this->artisan('sessions:start')->assertSuccessful();
        $this->assertSame($version, $room->fresh()->phase_version);
        $this->assertSame(ScheduledSession::STATUS_STARTED, $session->fresh()->status);
    }

    public function test_start_skips_future_sessions_and_cancels_archived_rooms(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $future = $this->makeSession($room, $host, ['starts_at' => now()->addHour()]);

        $this->artisan('sessions:start')->assertSuccessful();

        $this->assertSame(ScheduledSession::STATUS_SCHEDULED, $future->fresh()->status);
        $this->assertSame('idle', $room->fresh()->phase);

        $archived = $this->makeRoom($host, ['archived_at' => now()]);
        $orphaned = $this->makeSession($archived, $host, ['starts_at' => now()->subMinute()]);

        $this->artisan('sessions:start')->assertSuccessful();

        $this->assertSame(ScheduledSession::STATUS_CANCELLED, $orphaned->fresh()->status);
    }

    /*
    |----------------------------------------------------------------------
    | sessions:remind — 10-minute push reminder (PRD 4.10)
    |----------------------------------------------------------------------
    */

    public function test_reminder_pushes_to_rsvped_subscribers_exactly_once(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $mate = User::factory()->create();
        $this->join($room, $mate);

        $session = $this->makeSession($room, $host, ['starts_at' => now()->addMinutes(5)]);
        ScheduledSessionRsvp::create(['scheduled_session_id' => $session->id, 'user_id' => $mate->id]);
        PushSubscription::create([
            'user_id' => $mate->id,
            'endpoint' => 'https://push.example.com/sub/reminder',
            'p256dh' => PushTest::P256DH,
            'auth' => PushTest::AUTH,
        ]);

        $push = $this->mock(PushService::class);
        $push->shouldReceive('sendAll')
            ->once()
            ->withArgs(fn ($subscriptions, string $title, string $body, string $url): bool => $subscriptions->count() === 1
                && $title === 'Morning sprint'
                && str_contains($body, $room->name)
                && $url === '/room/'.$room->slug)
            ->andReturn(1);

        $this->artisan('sessions:remind')->assertSuccessful();
        $this->assertNotNull($session->refresh()->reminded_at);

        // The marker keeps the fan-out exactly once.
        $this->artisan('sessions:remind')->assertSuccessful();
    }

    public function test_reminder_skips_outside_the_window_and_without_subscribers(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $mate = User::factory()->create();
        $this->join($room, $mate);

        $tooFar = $this->makeSession($room, $host, ['starts_at' => now()->addMinutes(30)]);
        ScheduledSessionRsvp::create(['scheduled_session_id' => $tooFar->id, 'user_id' => $mate->id]);

        // RSVP'd but no push subscription: marked so we stop considering it,
        // without ever attempting a delivery.
        $noSubscription = $this->makeSession($room, $host, ['starts_at' => now()->addMinutes(8)]);
        ScheduledSessionRsvp::create(['scheduled_session_id' => $noSubscription->id, 'user_id' => $mate->id]);

        $push = $this->mock(PushService::class);
        $push->shouldNotReceive('sendAll');

        $this->artisan('sessions:remind')->assertSuccessful();

        $this->assertNull($tooFar->fresh()->reminded_at);
        $this->assertNotNull($noSubscription->fresh()->reminded_at);
    }
}

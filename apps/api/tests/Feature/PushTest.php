<?php

namespace Tests\Feature;

use App\Jobs\SendPhasePush;
use App\Models\PushSubscription;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use App\Services\PushService;
use App\Services\RoomClockService;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/**
 * Milestone 6 (PRD 4.10): Web Push — subscription upsert/unsubscribe,
 * dead-endpoint cleanup when the push service answers 404/410, and the
 * phase-change fan-out (only current room members with a subscription).
 *
 * Delivery runs against a recording PSR-18 fake: encryption and VAPID
 * signing execute for real (phpunit.xml pins the key pair), but nothing
 * ever leaves the process.
 */
class PushTest extends TestCase
{
    use RefreshDatabase;

    /** A valid uncompressed P-256 point so aes128gcm encryption succeeds. */
    public const P256DH = 'BOtslHIy4bNDcWiwWtE9j0oKmEtyfZJMCedAvKX7HUT3JqDvivt79LGv8Ej_85Zu4GLTVob24ffivuITA5T8d_s';

    public const AUTH = 'USGtWE0z1rUSsdw5ZRHwlw';

    private function makeRoom(User $host, array $overrides = []): Room
    {
        return Room::create(array_merge([
            'name' => 'Push Room',
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

    private function subscribe(User $user, string $endpoint, string $p256dh = self::P256DH, string $auth = self::AUTH): PushSubscription
    {
        return PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'p256dh' => $p256dh,
            'auth' => $auth,
        ]);
    }

    /*
    |----------------------------------------------------------------------
    | Subscribe / unsubscribe endpoints
    |----------------------------------------------------------------------
    */

    public function test_guest_cannot_subscribe(): void
    {
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => 'https://push.example.com/sub/x',
            'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH],
        ])->assertStatus(401);

        $this->deleteJson('/api/v1/push/subscribe', [
            'endpoint' => 'https://push.example.com/sub/x',
        ])->assertStatus(401);
    }

    public function test_subscribe_upserts_by_endpoint_and_moves_between_accounts(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $endpoint = 'https://push.example.com/sub/shared';

        $this->actingAs($alice)->postJson('/api/v1/push/subscribe', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH],
        ])->assertStatus(201);

        // Same browser endpoint re-subscribes under another account with
        // rotated keys: one row, now owned by Bob with the fresh keys.
        $rotated = str_pad(self::P256DH, 88, 'A');
        $this->actingAs($bob)->postJson('/api/v1/push/subscribe', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => $rotated, 'auth' => self::AUTH],
        ])->assertStatus(201);

        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => $endpoint,
            'user_id' => $bob->id,
            'p256dh' => $rotated,
        ]);
    }

    public function test_subscribe_validates_the_payload(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/push/subscribe', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint', 'keys']);

        $this->actingAs($user)->postJson('/api/v1/push/subscribe', [
            'endpoint' => 'not-a-url',
            'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH],
        ])->assertStatus(422)->assertJsonValidationErrors('endpoint');

        $this->actingAs($user)->postJson('/api/v1/push/subscribe', [
            'endpoint' => 'https://push.example.com/'.str_repeat('a', 800),
            'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH],
        ])->assertStatus(422)->assertJsonValidationErrors('endpoint');

        $this->actingAs($user)->postJson('/api/v1/push/subscribe', [
            'endpoint' => 'https://push.example.com/sub/x',
            'keys' => ['p256dh' => self::P256DH],
        ])->assertStatus(422)->assertJsonValidationErrors('keys.auth');

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_unsubscribe_is_scoped_to_the_caller_and_idempotent(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $endpoint = 'https://push.example.com/sub/alice';
        $this->subscribe($alice, $endpoint);

        // Bob cannot delete Alice's row.
        $this->actingAs($bob)->deleteJson('/api/v1/push/subscribe', ['endpoint' => $endpoint])
            ->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);

        // Alice unsubscribes; a second call stays a clean 200.
        $this->actingAs($alice)->deleteJson('/api/v1/push/subscribe', ['endpoint' => $endpoint])
            ->assertOk();
        $this->actingAs($alice)->deleteJson('/api/v1/push/subscribe', ['endpoint' => $endpoint])
            ->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    /*
    |----------------------------------------------------------------------
    | Delivery + dead-endpoint cleanup
    |----------------------------------------------------------------------
    */

    public function test_expired_subscription_is_deleted_when_push_service_says_gone(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'https://push.example.com/sub/gone');

        $client = new FakePushClient(410);
        $sent = (new PushService($client))->sendAll([$subscription], 'DeskMates', 'Focus time', '/room/x');

        $this->assertSame(0, $sent);
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => $subscription->endpoint]);
        $this->assertCount(1, $client->requests);
    }

    public function test_failed_delivery_keeps_the_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'https://push.example.com/sub/flaky');

        $client = new FakePushClient(500);
        $sent = (new PushService($client))->sendAll([$subscription], 'DeskMates', 'Focus time', '/room/x');

        $this->assertSame(0, $sent);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $subscription->endpoint]);
    }

    public function test_successful_delivery_is_counted(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'https://push.example.com/sub/ok');

        $client = new FakePushClient(200);
        $sent = (new PushService($client))->sendAll([$subscription], 'DeskMates', 'Focus time', '/room/x');

        $this->assertSame(1, $sent);
        $this->assertCount(1, $client->requests);
        $this->assertSame($subscription->endpoint, (string) $client->requests[0]->getUri());
    }

    public function test_delivery_is_skipped_when_vapid_is_unconfigured(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'https://push.example.com/sub/nope');

        config(['services.vapid.public' => null, 'services.vapid.private' => null]);

        $client = new FakePushClient(200);
        $sent = (new PushService($client))->sendAll([$subscription], 'DeskMates', 'Focus time', '/room/x');

        $this->assertSame(0, $sent);
        $this->assertCount(0, $client->requests);
    }

    /*
    |----------------------------------------------------------------------
    | Phase-change fan-out
    |----------------------------------------------------------------------
    */

    public function test_phase_push_targets_current_room_members_only(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $this->join($room, $member);

        $memberSub = $this->subscribe($member, 'https://push.example.com/sub/member');
        $this->subscribe($outsider, 'https://push.example.com/sub/outsider');

        $client = new FakePushClient(200);
        (new SendPhasePush($room->id, $room->slug, $room->name, RoomClockService::PHASE_FOCUS))
            ->handle(new PushService($client));

        $this->assertCount(1, $client->requests);
        $this->assertSame($memberSub->endpoint, (string) $client->requests[0]->getUri());
    }

    public function test_starting_a_timer_queues_a_phase_push_but_pausing_does_not(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $clock = app(RoomClockService::class);

        Queue::fake();
        $clock->transition($room, 'start');
        Queue::assertPushed(
            SendPhasePush::class,
            fn (SendPhasePush $job): bool => $job->roomId === $room->id
                && $job->phase === RoomClockService::PHASE_FOCUS
        );

        // pause lands on idle — the guard keeps it silent (PRD 4.10).
        $room->forceFill([
            'phase' => RoomClockService::PHASE_FOCUS,
            'phase_started_at' => now(),
            'phase_ends_at' => now()->addMinutes(25),
            'phase_version' => 1,
        ])->save();

        Queue::fake();
        $clock->transition($room, 'pause');
        Queue::assertNotPushed(SendPhasePush::class);
    }

    public function test_always_on_sweep_queues_a_phase_push(): void
    {
        Room::updateOrCreate(['slug' => 'the-library'], [
            'name' => 'The Library',
            'type' => Room::TYPE_ALWAYS_ON,
            'anchor_epoch' => now()->subMinutes(10)->getTimestamp(),
            'phase' => 'idle',
        ]);

        Queue::fake();
        $this->artisan('rooms:broadcast-always-on')->assertSuccessful();

        Queue::assertPushed(
            SendPhasePush::class,
            fn (SendPhasePush $job): bool => $job->phase === RoomClockService::PHASE_FOCUS
        );
    }
}

/**
 * Records every push request and answers with a canned status so the
 * library's full encrypt → sign → send path runs without a network.
 */
final class FakePushClient implements ClientInterface
{
    /** @var array<int, RequestInterface> */
    public array $requests = [];

    public function __construct(private readonly int $status = 200) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return new GuzzleResponse($this->status);
    }
}

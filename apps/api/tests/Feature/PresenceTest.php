<?php

namespace Tests\Feature;

use App\Events\IntentionUpdated;
use App\Events\MemberRemoved;
use App\Events\MemberStatusChanged;
use App\Events\RoomSettingsUpdated;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Concerns\AuthorizesPusherChannels;
use Tests\TestCase;

/**
 * Milestone 3 (PRD 4.4/4.5): heartbeats, stale reaper, one-room rule,
 * intentions, presence channel authorization. PRD instruction #7 requires
 * heartbeat and reaper logic to be covered by tests.
 */
class PresenceTest extends TestCase
{
    use AuthorizesPusherChannels;
    use RefreshDatabase;

    private function makeRoom(User $host, array $overrides = []): Room
    {
        return Room::create(array_merge([
            'name' => 'Presence Room',
            'type' => Room::TYPE_PUBLIC,
            'host_id' => $host->id,
            'focus_minutes' => 25,
            'short_break_minutes' => 5,
            'long_break_minutes' => 15,
            'long_break_every' => 4,
            'last_active_at' => now(),
        ], $overrides));
    }

    private function join(Room $room, User $user, array $overrides = []): RoomMember
    {
        return RoomMember::create(array_merge([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'joined_at' => now(),
            'last_seen_at' => now(),
            'status' => RoomMember::STATUS_BREAK,
        ], $overrides));
    }

    /*
    |----------------------------------------------------------------------
    | Heartbeats (PRD 4.4)
    |----------------------------------------------------------------------
    */

    public function test_heartbeat_touches_presence_and_reports_status(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $member = $this->join($room, $host);

        $response = $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/heartbeat", [
            'visible' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', RoomMember::STATUS_BREAK)
            ->assertJsonPath('rejoined', false);

        $member->refresh();
        $this->assertNotNull($member->last_seen_at);
        $this->assertNull($member->hidden_since);
        // ±1 s: the heartbeat and the assertion can straddle a second
        // boundary (PRD 11 allows a displayed time within ±1 s).
        $this->assertEqualsWithDelta(now()->getTimestamp(), $member->last_seen_at->getTimestamp(), 1);
        $this->assertEqualsWithDelta(now()->getTimestamp(), $room->fresh()->last_active_at->getTimestamp(), 1);
    }

    public function test_heartbeat_during_focus_reports_focusing(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/timer/start")
            ->assertOk();

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/heartbeat", [
            'visible' => true,
        ])->assertOk()->assertJsonPath('status', RoomMember::STATUS_FOCUSING);
    }

    public function test_hidden_tab_goes_away_after_two_minutes_and_broadcasts_once(): void
    {
        Event::fake([MemberStatusChanged::class]);

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        // Hidden but under the threshold: still on break, no broadcast.
        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/heartbeat", [
            'visible' => false,
        ])->assertOk()->assertJsonPath('status', RoomMember::STATUS_BREAK);

        Event::assertNotDispatched(MemberStatusChanged::class);

        // Past 2 minutes of hiding: away, and everyone is told once.
        $this->travel(RoomMember::AWAY_AFTER_MINUTES + 1)->minutes();

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/heartbeat", [
            'visible' => false,
        ])->assertOk()->assertJsonPath('status', RoomMember::STATUS_AWAY);

        Event::assertDispatchedTimes(MemberStatusChanged::class, 1);
        Event::assertDispatched(
            MemberStatusChanged::class,
            fn (MemberStatusChanged $e) => $e->userId === $host->id && $e->status === RoomMember::STATUS_AWAY,
        );

        // Staying hidden must not re-broadcast the same state.
        $this->travel(RoomMember::AWAY_AFTER_MINUTES + 1)->minutes();

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/heartbeat", [
            'visible' => false,
        ])->assertOk()->assertJsonPath('status', RoomMember::STATUS_AWAY);

        Event::assertDispatchedTimes(MemberStatusChanged::class, 1);
    }

    public function test_visible_heartbeat_clears_away_state(): void
    {
        Event::fake([MemberStatusChanged::class]);

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        // A member hidden this long already reads as "away" to anyone loading
        // the room, so the persisted status must agree with hidden_since.
        $member = $this->join($room, $host, [
            'hidden_since' => now()->subMinutes(5),
            'status' => RoomMember::STATUS_AWAY,
        ]);

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/heartbeat", [
            'visible' => true,
        ])->assertOk()->assertJsonPath('status', RoomMember::STATUS_BREAK);

        $this->assertNull($member->fresh()->hidden_since);

        Event::assertDispatched(
            MemberStatusChanged::class,
            fn (MemberStatusChanged $e) => $e->status === RoomMember::STATUS_BREAK,
        );
    }

    public function test_heartbeat_auto_rejoins_a_member_reaped_while_away(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $member = $this->join($room, $host);
        $member->delete(); // simulates the reaper having removed them

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/heartbeat", [
            'visible' => true,
        ])->assertOk()->assertJsonPath('rejoined', true);

        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $host->id]);
    }

    public function test_heartbeat_rejects_private_rooms_without_membership(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host, ['type' => Room::TYPE_PRIVATE]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->postJson("/api/v1/rooms/{$room->id}/heartbeat", [
            'visible' => true,
        ])->assertStatus(403);

        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $intruder->id]);
    }

    public function test_heartbeat_reports_membership_in_another_room(): void
    {
        $user = User::factory()->create();
        $elsewhere = $this->makeRoom($user, ['name' => 'Elsewhere', 'slug' => 'elsewhere-room']);
        $this->join($elsewhere, $user);

        $here = User::factory()->create(); // host of the room being heartbeated
        $room = $this->makeRoom($here);

        $this->actingAs($user)->postJson("/api/v1/rooms/{$room->id}/heartbeat", [
            'visible' => true,
        ])->assertStatus(409)
            ->assertJsonPath('current_room_slug', 'elsewhere-room');

        // No silent tug of war: the original membership stays put.
        $this->assertDatabaseHas('room_members', ['room_id' => $elsewhere->id, 'user_id' => $user->id]);
    }

    public function test_heartbeat_is_rate_limited(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        $payload = ['visible' => true];

        // 4/min window (PRD 6: heartbeats, 1 per 15 s minimum).
        collect(range(1, 4))->each(
            fn () => $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/heartbeat", $payload)->assertOk(),
        );

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/heartbeat", $payload)
            ->assertStatus(429);

        // A minute later the window has expired again.
        $this->travel(61)->seconds();

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/heartbeat", $payload)
            ->assertOk();
    }

    /*
    |----------------------------------------------------------------------
    | Stale reaper (PRD 4.4/7.3)
    |----------------------------------------------------------------------
    */

    public function test_reaper_removes_stale_members_and_broadcasts_leave(): void
    {
        Event::fake([MemberRemoved::class]);

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $stale = $this->join($room, $host, ['last_seen_at' => now()->subSeconds(61)]);

        $freshUser = User::factory()->create();
        $this->join($room, $freshUser);

        $this->artisan('presence:reap-stale')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('room_members', ['id' => $stale->id]);
        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $freshUser->id]);

        Event::assertDispatched(
            MemberRemoved::class,
            fn (MemberRemoved $e) => $e->roomId === $room->id && $e->userId === $host->id,
        );
    }

    public function test_reaper_leaves_fresh_members_alone(): void
    {
        Event::fake([MemberRemoved::class]);

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $member = $this->join($room, $host, ['last_seen_at' => now()->subSeconds(30)]);

        $this->artisan('presence:reap-stale')->assertExitCode(0);

        $this->assertDatabaseHas('room_members', ['id' => $member->id]);
        Event::assertNotDispatched(MemberRemoved::class);
    }

    public function test_reaper_transfers_hostship_to_the_longest_present_member(): void
    {
        Event::fake([MemberRemoved::class, RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host, ['last_seen_at' => now()->subSeconds(61)]);

        $oldest = User::factory()->create();
        $this->join($room, $oldest, ['joined_at' => now()->subHour(), 'last_seen_at' => now()]);

        $youngest = User::factory()->create();
        $this->join($room, $youngest, ['joined_at' => now(), 'last_seen_at' => now()]);

        $this->artisan('presence:reap-stale')->assertExitCode(0);

        $this->assertEquals($oldest->id, $room->fresh()->host_id);

        Event::assertDispatched(
            RoomSettingsUpdated::class,
            fn (RoomSettingsUpdated $e) => $e->roomId === $room->id && $e->hostId === $oldest->id,
        );
        Event::assertDispatched(
            MemberRemoved::class,
            fn (MemberRemoved $e) => $e->roomId === $room->id && $e->userId === $host->id,
        );
    }

    public function test_reaper_keeps_an_empty_rooms_host(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host, ['last_seen_at' => now()->subSeconds(61)]);

        $this->artisan('presence:reap-stale')->assertExitCode(0);

        // Nobody left to promote; the host reclaims controls on return.
        $this->assertEquals($host->id, $room->fresh()->host_id);
        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id]);
    }

    /*
    |----------------------------------------------------------------------
    | One-room rule and host transfer (PRD M3)
    |----------------------------------------------------------------------
    */

    public function test_joining_a_second_room_kicks_the_first_and_transfers_its_host(): void
    {
        Event::fake([MemberRemoved::class, RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $first = $this->makeRoom($host, ['name' => 'First', 'slug' => 'first-room']);
        $this->join($first, $host);

        $otherMember = User::factory()->create();
        $this->join($first, $otherMember, ['joined_at' => now()->subHour()]);

        $second = User::factory()->create();
        $secondRoom = $this->makeRoom($second, ['name' => 'Second', 'slug' => 'second-room']);

        $this->actingAs($host)->postJson("/api/v1/rooms/{$secondRoom->id}/join")->assertOk();

        // One-room rule: the membership moved, it did not duplicate.
        $this->assertDatabaseHas('room_members', ['room_id' => $secondRoom->id, 'user_id' => $host->id]);
        $this->assertDatabaseMissing('room_members', ['room_id' => $first->id, 'user_id' => $host->id]);

        // The old room learned about the departure and its new host live.
        $this->assertEquals($otherMember->id, $first->fresh()->host_id);

        Event::assertDispatched(
            MemberRemoved::class,
            fn (MemberRemoved $e) => $e->roomId === $first->id && $e->userId === $host->id,
        );
        Event::assertDispatched(
            RoomSettingsUpdated::class,
            fn (RoomSettingsUpdated $e) => $e->roomId === $first->id && $e->hostId === $otherMember->id,
        );
    }

    public function test_leaving_a_room_transfers_hostship_and_broadcasts(): void
    {
        Event::fake([MemberRemoved::class, RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        $member = User::factory()->create();
        $this->join($room, $member, ['joined_at' => now()->subHour()]);

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/leave")->assertOk();

        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $host->id]);
        $this->assertEquals($member->id, $room->fresh()->host_id);

        Event::assertDispatched(MemberRemoved::class);
        Event::assertDispatched(
            RoomSettingsUpdated::class,
            fn (RoomSettingsUpdated $e) => $e->hostId === $member->id,
        );
    }

    /*
    |----------------------------------------------------------------------
    | Intentions (PRD 4.5)
    |----------------------------------------------------------------------
    */

    public function test_setting_an_intention_persists_and_broadcasts(): void
    {
        Event::fake([IntentionUpdated::class]);

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        $this->actingAs($host)->patchJson("/api/v1/rooms/{$room->id}/intention", [
            'intention' => 'Review PRD section 4',
        ])->assertOk()->assertJsonPath('intention', 'Review PRD section 4');

        $this->assertDatabaseHas('room_members', [
            'room_id' => $room->id,
            'user_id' => $host->id,
            'intention' => 'Review PRD section 4',
        ]);

        Event::assertDispatched(
            IntentionUpdated::class,
            fn (IntentionUpdated $e) => $e->roomId === $room->id
                && $e->userId === $host->id
                && $e->intention === 'Review PRD section 4',
        );
    }

    public function test_intention_can_be_cleared(): void
    {
        Event::fake([IntentionUpdated::class]);

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host, ['intention' => 'Old plan']);

        $this->actingAs($host)->patchJson("/api/v1/rooms/{$room->id}/intention", [
            'intention' => null,
        ])->assertOk()->assertJsonPath('intention', null);

        $this->assertDatabaseHas('room_members', [
            'room_id' => $room->id,
            'user_id' => $host->id,
            'intention' => null,
        ]);
    }

    public function test_intention_requires_membership_and_at_most_80_characters(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->patchJson("/api/v1/rooms/{$room->id}/intention", ['intention' => 'sneaky'])
            ->assertStatus(403);

        $this->join($room, $host);

        $this->actingAs($host)
            ->patchJson("/api/v1/rooms/{$room->id}/intention", ['intention' => str_repeat('x', 81)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['intention']);

        $this->actingAs($host)
            ->patchJson("/api/v1/rooms/{$room->id}/intention", ['intention' => str_repeat('x', 80)])
            ->assertOk();
    }

    /*
    |----------------------------------------------------------------------
    | Room state and presence channel (PRD 4.4, 7.2)
    |----------------------------------------------------------------------
    */

    public function test_room_state_returns_member_cards_with_fresh_status(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host, ['intention' => 'Ship M3']);

        $mate = User::factory()->create();
        $this->join($room, $mate, ['hidden_since' => now()->subMinutes(10)]);

        $response = $this->actingAs($host)->getJson("/api/v1/rooms/{$room->slug}");

        $response->assertOk();

        $members = collect($response->json('members'));

        $hostCard = $members->firstWhere('user_id', $host->id);
        $this->assertEquals(RoomMember::STATUS_BREAK, $hostCard['status']);
        $this->assertEquals('Ship M3', $hostCard['intention']);
        $this->assertTrue($hostCard['is_host']);
        $this->assertNotNull($hostCard['name']);

        $mateCard = $members->firstWhere('user_id', $mate->id);
        $this->assertEquals(RoomMember::STATUS_AWAY, $mateCard['status']);
        $this->assertFalse($mateCard['is_host']);
    }

    public function test_presence_channel_requires_authentication(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);

        $this->postJson('/broadcasting/auth', [
            'channel_name' => "presence-room.{$room->id}",
            'socket_id' => '123.456',
        ])->assertStatus(401);
    }

    public function test_members_can_authorize_the_presence_channel(): void
    {
        $this->usePusherChannelAuth();

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        $response = $this->actingAs($host)->postJson('/broadcasting/auth', [
            'channel_name' => "presence-room.{$room->id}",
            'socket_id' => '123.456',
        ]);

        $response->assertOk();

        $payload = json_decode($response->content(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('auth', $payload);
        $this->assertArrayHasKey('channel_data', $payload);

        // The user_info embedded in channel_data feeds .here()/.joining().
        $channelData = json_decode($payload['channel_data'], true);
        $this->assertEquals($host->id, $channelData['user_id']);
        $this->assertEquals($host->name, $channelData['user_info']['name']);
    }

    public function test_private_rooms_reject_non_members_from_presence(): void
    {
        $this->usePusherChannelAuth();

        $host = User::factory()->create();
        $room = $this->makeRoom($host, ['type' => Room::TYPE_PRIVATE]);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->postJson('/broadcasting/auth', [
            'channel_name' => "presence-room.{$room->id}",
            'socket_id' => '123.456',
        ])->assertStatus(403);
    }

    public function test_full_rooms_reject_non_members_from_presence(): void
    {
        $this->usePusherChannelAuth();

        $host = User::factory()->create();
        $room = $this->makeRoom($host, ['max_members' => 2]);
        $member = User::factory()->create();
        $this->join($room, $host);
        $this->join($room, $member); // now full

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->postJson('/broadcasting/auth', [
            'channel_name' => "presence-room.{$room->id}",
            'socket_id' => '123.456',
        ])->assertStatus(403);

        // A member of the full room still passes (they are already inside).
        $this->actingAs($member)->postJson('/broadcasting/auth', [
            'channel_name' => "presence-room.{$room->id}",
            'socket_id' => '123.456',
        ])->assertOk();
    }

    public function test_public_rooms_let_anyone_watch_presence(): void
    {
        $this->usePusherChannelAuth();

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $watcher = User::factory()->create();

        $this->actingAs($watcher)->postJson('/broadcasting/auth', [
            'channel_name' => "presence-room.{$room->id}",
            'socket_id' => '123.456',
        ])->assertOk();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomTest extends TestCase
{
    use RefreshDatabase;

    public function test_lobby_lists_always_on_and_public_rooms(): void
    {
        Room::updateOrCreate(
            ['slug' => 'the-library'],
            ['name' => 'The Library', 'type' => Room::TYPE_ALWAYS_ON, 'anchor_epoch' => 1735689600],
        );
        $public = Room::create(['name' => 'Focus Friends', 'type' => Room::TYPE_PUBLIC, 'host_id' => User::factory()->create()->id]);
        RoomMember::create(['room_id' => $public->id, 'user_id' => $public->host_id, 'joined_at' => now(), 'last_seen_at' => now()]);
        Room::create(['name' => 'Secret', 'type' => Room::TYPE_PRIVATE, 'host_id' => User::factory()->create()->id, 'invite_code' => 'SECRET12']);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/rooms');

        $response->assertOk()
            ->assertJsonCount(2, 'rooms')
            ->assertJsonPath('rooms.0.slug', 'the-library')
            ->assertJsonPath('rooms.1.id', $public->id)
            ->assertJsonPath('rooms.1.member_count', 1);

        // Lobby payloads carry server clock for client offset computation.
        $this->assertArrayHasKey('server_now_ms', $response->json());
    }

    public function test_guest_cannot_list_rooms(): void
    {
        $this->getJson('/api/v1/rooms')->assertStatus(401);
    }

    public function test_user_can_create_a_public_room_and_becomes_host_and_member(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/rooms', [
            'name' => 'Exam Grind',
            'type' => Room::TYPE_PUBLIC,
            'focus_minutes' => 50,
            'short_break_minutes' => 10,
            'long_break_minutes' => 20,
            'long_break_every' => 3,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('room.name', 'Exam Grind')
            ->assertJsonPath('room.is_host', true)
            ->assertJsonPath('room.slug', 'exam-grind')
            ->assertJsonPath('room.focus_minutes', 50);

        $room = Room::where('slug', 'exam-grind')->firstOrFail();
        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $user->id]);
    }

    public function test_private_room_gets_an_invite_code(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/rooms', [
            'name' => 'Study Group',
            'type' => Room::TYPE_PRIVATE,
            'focus_minutes' => 25,
            'short_break_minutes' => 5,
            'long_break_minutes' => 15,
            'long_break_every' => 4,
        ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('room.invite_code'));
    }

    public function test_timer_configuration_limits_are_enforced(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/rooms', [
            'name' => 'Too Long',
            'type' => Room::TYPE_PUBLIC,
            'focus_minutes' => 91,
            'short_break_minutes' => 2,
            'long_break_minutes' => 9,
            'long_break_every' => 7,
        ])->assertStatus(422)->assertJsonValidationErrors([
            'focus_minutes', 'short_break_minutes', 'long_break_minutes', 'long_break_every',
        ]);
    }

    public function test_max_members_cannot_exceed_type_limit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/rooms', [
            'name' => 'Too Big',
            'type' => Room::TYPE_PRIVATE,
            'focus_minutes' => 25,
            'short_break_minutes' => 5,
            'long_break_minutes' => 15,
            'long_break_every' => 4,
            'max_members' => 13,
        ])->assertStatus(422)->assertJsonValidationErrors(['max_members']);
    }

    public function test_users_cannot_create_always_on_rooms(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/rooms', [
            'name' => 'Official Impostor',
            'type' => Room::TYPE_ALWAYS_ON,
            'focus_minutes' => 25,
            'short_break_minutes' => 5,
            'long_break_minutes' => 15,
            'long_break_every' => 4,
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_private_room_show_requires_membership(): void
    {
        $owner = User::factory()->create();
        $room = Room::create([
            'name' => 'Secret Study',
            'type' => Room::TYPE_PRIVATE,
            'host_id' => $owner->id,
            'invite_code' => 'INVITE99',
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson("/api/v1/rooms/{$room->slug}")->assertStatus(403);
        $this->actingAs($owner)->getJson("/api/v1/rooms/{$room->slug}")->assertOk();
    }

    public function test_joining_a_room_moves_the_member_from_their_previous_room(): void
    {
        $user = User::factory()->create();
        $first = Room::create(['name' => 'First', 'type' => Room::TYPE_PUBLIC, 'host_id' => User::factory()->create()->id]);
        $second = Room::create(['name' => 'Second', 'type' => Room::TYPE_PUBLIC, 'host_id' => User::factory()->create()->id]);

        RoomMember::create(['room_id' => $first->id, 'user_id' => $user->id, 'joined_at' => now(), 'last_seen_at' => now()]);

        $this->actingAs($user)->postJson("/api/v1/rooms/{$second->id}/join")->assertOk();

        // One active room per user: the unique index on room_members.user_id.
        $this->assertDatabaseHas('room_members', ['user_id' => $user->id, 'room_id' => $second->id]);
        $this->assertDatabaseMissing('room_members', ['user_id' => $user->id, 'room_id' => $first->id]);
    }

    public function test_joining_private_room_requires_invite_code(): void
    {
        $owner = User::factory()->create();
        $room = Room::create([
            'name' => 'Invite Only',
            'type' => Room::TYPE_PRIVATE,
            'host_id' => $owner->id,
            'invite_code' => 'CORRECT1',
        ]);
        $guest = User::factory()->create();

        $this->actingAs($guest)
            ->postJson("/api/v1/rooms/{$room->id}/join", ['invite_code' => 'WRONG123'])
            ->assertStatus(422)->assertJsonValidationErrors(['invite_code']);

        $this->actingAs($guest)
            ->postJson("/api/v1/rooms/{$room->id}/join", ['invite_code' => 'CORRECT1'])
            ->assertOk();
    }

    public function test_only_host_can_update_room_settings(): void
    {
        $host = User::factory()->create();
        $room = Room::create(['name' => 'Host Room', 'type' => Room::TYPE_PUBLIC, 'host_id' => $host->id]);
        $member = User::factory()->create();
        RoomMember::create(['room_id' => $room->id, 'user_id' => $member->id, 'joined_at' => now()]);

        $this->actingAs($member)
            ->patchJson("/api/v1/rooms/{$room->id}", ['focus_minutes' => 60])
            ->assertStatus(403);

        $this->actingAs($host)
            ->patchJson("/api/v1/rooms/{$room->id}", ['focus_minutes' => 60])
            ->assertOk()->assertJsonPath('room.focus_minutes', 60);
    }

    public function test_always_on_rooms_cannot_be_updated_or_closed(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $room = Room::create(['name' => 'The Library', 'type' => Room::TYPE_ALWAYS_ON, 'anchor_epoch' => 1735689600]);

        // Even an admin cannot mutate an always-on room's timer (deterministic clock).
        $this->actingAs($user)
            ->patchJson("/api/v1/rooms/{$room->id}", ['focus_minutes' => 60])
            ->assertStatus(403);

        $this->actingAs($user)
            ->deleteJson("/api/v1/rooms/{$room->id}")
            ->assertStatus(403);
    }

    public function test_host_can_close_their_room(): void
    {
        $host = User::factory()->create();
        $room = Room::create(['name' => 'Closing', 'type' => Room::TYPE_PUBLIC, 'host_id' => $host->id]);

        $this->actingAs($host)->deleteJson("/api/v1/rooms/{$room->id}")->assertOk();

        $this->assertNotNull($room->fresh()->archived_at);
        $this->actingAs($host)->getJson("/api/v1/rooms/{$room->slug}")->assertStatus(404);
    }

    public function test_time_endpoint_returns_server_clock(): void
    {
        $this->getJson('/api/v1/time')
            ->assertOk()
            ->assertJsonStructure(['server_now_ms']);
    }
}

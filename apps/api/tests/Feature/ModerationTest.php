<?php

namespace Tests\Feature;

use App\Events\MemberKicked;
use App\Events\MemberMuted;
use App\Events\MemberRemoved;
use App\Events\RoomSettingsUpdated;
use App\Models\Message;
use App\Models\Report;
use App\Models\Room;
use App\Models\RoomBan;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Concerns\AuthorizesPusherChannels;
use Tests\TestCase;

/**
 * Milestone 4 (PRD 4.6/4.12): kick, ban, mute and the report queue —
 * "abuse tools function end to end" (M4 done-when) with channel
 * authorization rejecting banned users (PRD 7.2).
 */
class ModerationTest extends TestCase
{
    use AuthorizesPusherChannels;
    use RefreshDatabase;

    private function makeRoom(?User $host, array $overrides = []): Room
    {
        return Room::create(array_merge([
            'name' => 'Mod Room',
            'type' => Room::TYPE_PUBLIC,
            'host_id' => $host?->id,
            'focus_minutes' => 25,
            'short_break_minutes' => 5,
            'long_break_minutes' => 15,
            'long_break_every' => 4,
            'last_active_at' => now(),
        ], $overrides));
    }

    private function join(Room $room, User $user): RoomMember
    {
        return RoomMember::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'joined_at' => now(),
            'last_seen_at' => now(),
            'status' => RoomMember::STATUS_BREAK,
        ]);
    }

    /*
    |----------------------------------------------------------------------
    | Kick (PRD 4.12)
    |----------------------------------------------------------------------
    */

    public function test_host_kicks_a_member_and_they_can_rejoin(): void
    {
        Event::fake([MemberKicked::class, MemberRemoved::class]);

        $host = User::factory()->create();
        $mate = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $mate);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/members/{$mate->id}/kick")
            ->assertOk()
            ->assertJsonPath('message', 'Member kicked. They can rejoin.');

        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $mate->id]);

        Event::assertDispatched(MemberRemoved::class, fn (MemberRemoved $e): bool => $e->roomId === $room->id && $e->userId === $mate->id);
        Event::assertDispatched(MemberKicked::class, fn (MemberKicked $e): bool => $e->userId === $mate->id && $e->reason === 'kicked');

        // A kick is not a ban: the seat opens again.
        $this->actingAs($mate)
            ->postJson("/api/v1/rooms/{$room->id}/join")
            ->assertOk();

        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $mate->id]);
    }

    public function test_ban_removes_the_member_and_blocks_every_reentry_path(): void
    {
        Event::fake([MemberKicked::class]);

        $host = User::factory()->create();
        $mate = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $mate);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/members/{$mate->id}/ban")
            ->assertOk()
            ->assertJsonPath('message', 'Member banned. They cannot rejoin.');

        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $mate->id]);
        $this->assertDatabaseHas('room_bans', ['room_id' => $room->id, 'user_id' => $mate->id]);

        Event::assertDispatched(MemberKicked::class, fn (MemberKicked $e): bool => $e->userId === $mate->id && $e->reason === 'banned');

        // Rejoin refused...
        $this->actingAs($mate)
            ->postJson("/api/v1/rooms/{$room->id}/join")
            ->assertStatus(403)
            ->assertJsonPath('message', 'You are banned from this room.');

        // ...and so is the heartbeat self-heal that would resurrect the seat.
        $this->actingAs($mate)
            ->postJson("/api/v1/rooms/{$room->id}/heartbeat", ['visible' => true])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You are banned from this room.');
    }

    public function test_banned_user_cannot_authorize_the_presence_channel(): void
    {
        $host = User::factory()->create();
        $banned = User::factory()->create();
        $room = $this->makeRoom($host);

        RoomBan::create(['room_id' => $room->id, 'user_id' => $banned->id, 'banned_by' => $host->id]);

        $this->usePusherChannelAuth();

        $this->actingAs($banned)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "presence-room.{$room->id}",
                'socket_id' => '123.456',
            ])
            ->assertStatus(403);

        // Control: an ordinary member still authorizes.
        $member = User::factory()->create();
        $this->join($room, $member);

        $this->actingAs($member)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "presence-room.{$room->id}",
                'socket_id' => '123.456',
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_member_cannot_kick_or_mute(): void
    {
        $host = User::factory()->create();
        $mate = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $mate);

        $this->actingAs($mate)
            ->postJson("/api/v1/rooms/{$room->id}/members/{$host->id}/kick")
            ->assertStatus(403);

        $this->actingAs($mate)
            ->postJson("/api/v1/rooms/{$room->id}/members/{$host->id}/mute", ['muted' => true])
            ->assertStatus(403);
    }

    public function test_host_cannot_remove_or_mute_themselves(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/members/{$host->id}/kick")
            ->assertStatus(422);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/members/{$host->id}/ban")
            ->assertStatus(422);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/members/{$host->id}/mute", ['muted' => true])
            ->assertStatus(422);
    }

    public function test_admin_kick_transfers_hostship_to_the_remaining_member(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $mate = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $room = $this->makeRoom($host);
        // Both seats must exist: kick targets must be in the room (404
        // otherwise), and host transfer needs a remaining member to receive
        // the hostship.
        $this->join($room, $host);
        $this->join($room, $mate);

        $this->actingAs($admin)
            ->postJson("/api/v1/rooms/{$room->id}/members/{$host->id}/kick")
            ->assertOk();

        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $host->id]);
        $this->assertSame($mate->id, (int) $room->fresh()->host_id);

        Event::assertDispatched(RoomSettingsUpdated::class, fn (RoomSettingsUpdated $e): bool => $e->roomId === $room->id && $e->hostId === $mate->id);
    }

    /*
    |----------------------------------------------------------------------
    | Mute (PRD 4.6)
    |----------------------------------------------------------------------
    */

    public function test_mute_toggles_the_seat_and_broadcasts(): void
    {
        Event::fake([MemberMuted::class]);

        $host = User::factory()->create();
        $mate = User::factory()->create();
        $room = $this->makeRoom($host);
        $member = $this->join($room, $mate);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/members/{$mate->id}/mute", ['muted' => true])
            ->assertOk()
            ->assertJsonPath('message', 'Member muted.');

        $this->assertTrue((bool) $member->fresh()->is_muted);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/members/{$mate->id}/mute", ['muted' => false])
            ->assertOk()
            ->assertJsonPath('message', 'Member unmuted.');

        $this->assertFalse((bool) $member->fresh()->is_muted);

        Event::assertDispatchedTimes(MemberMuted::class, 2);
        Event::assertDispatched(MemberMuted::class, fn (MemberMuted $e): bool => $e->roomId === $room->id && $e->userId === $mate->id && $e->muted === true);
    }

    public function test_mute_requires_a_member_and_a_boolean(): void
    {
        $host = User::factory()->create();
        $outsider = User::factory()->create();
        $room = $this->makeRoom($host);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/members/{$outsider->id}/mute", ['muted' => true])
            ->assertStatus(404);

        $this->join($room, $outsider);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/members/{$outsider->id}/mute", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('muted');
    }

    public function test_always_on_rooms_are_moderated_by_admins_only(): void
    {
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        // Always-on rooms have no host (M1 decision: immutable clock,
        // admins moderate users).
        $room = $this->makeRoom(null, ['type' => Room::TYPE_ALWAYS_ON, 'anchor_epoch' => 1735689600]);
        $seat = $this->join($room, $member);

        $this->actingAs($member)
            ->postJson("/api/v1/rooms/{$room->id}/members/{$member->id}/mute", ['muted' => true])
            ->assertStatus(403);

        $this->actingAs($admin)
            ->postJson("/api/v1/rooms/{$room->id}/members/{$member->id}/mute", ['muted' => true])
            ->assertOk();

        $this->assertTrue((bool) $seat->fresh()->is_muted);
    }

    /**
     * Reports and the admin queue (PRD 4.12).
     */
    public function test_report_reaches_the_admin_queue_and_can_be_reviewed(): void
    {
        $host = User::factory()->create();
        $reporter = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $room = $this->makeRoom($host);
        $this->join($room, $reporter);

        $sent = $this->actingAs($reporter)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'something reportable'])
            ->assertCreated()
            ->json('message');

        $created = $this->actingAs($reporter)
            ->postJson('/api/v1/reports', [
                'room_id' => $room->id,
                'target_user_id' => $host->id,
                'message_id' => $sent['id'],
                'reason' => 'Off-topic spam in a focus room',
            ])
            ->assertCreated();

        $reportId = $created->json('report.id');
        $this->assertSame('open', $created->json('report.status'));

        $queue = $this->actingAs($admin)
            ->getJson('/api/v1/admin/reports')
            ->assertOk()
            ->json('reports');

        $this->assertCount(1, $queue);
        $this->assertSame($reportId, $queue[0]['id']);
        $this->assertSame('Off-topic spam in a focus room', $queue[0]['reason']);
        $this->assertSame($host->id, $queue[0]['target']['id']);
        $this->assertSame($sent['id'], $queue[0]['message']['id']);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/reports/{$reportId}", ['status' => 'reviewed'])
            ->assertOk()
            ->assertJsonPath('report.status', 'reviewed');

        $this->assertDatabaseHas('reports', ['id' => $reportId, 'status' => 'reviewed']);
    }

    public function test_report_queue_is_admin_only(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $room = $this->makeRoom($admin);

        $report = Report::create([
            'reporter_id' => $user->id,
            'target_user_id' => $admin->id,
            'room_id' => $room->id,
            'reason' => 'queued already',
            'status' => 'open',
        ]);

        $this->actingAs($user)->getJson('/api/v1/admin/reports')->assertStatus(403);
        $this->actingAs($user)
            ->patchJson("/api/v1/admin/reports/{$report->id}", ['status' => 'dismissed'])
            ->assertStatus(403);
    }

    public function test_report_validates_its_target_and_message(): void
    {
        $host = User::factory()->create();
        $reporter = User::factory()->create();
        $room = $this->makeRoom($host);
        $other = $this->makeRoom($host, ['name' => 'Other Room']);

        // Self-report refused.
        $this->actingAs($reporter)
            ->postJson('/api/v1/reports', [
                'room_id' => $room->id,
                'target_user_id' => $reporter->id,
                'reason' => 'I did it to myself',
            ])
            ->assertStatus(422);

        // The reported message must live in the reported room.
        $foreign = Message::create([
            'room_id' => $other->id,
            'user_id' => $host->id,
            'body' => 'from another room',
        ]);

        $this->actingAs($reporter)
            ->postJson('/api/v1/reports', [
                'room_id' => $room->id,
                'target_user_id' => $host->id,
                'message_id' => $foreign->id,
                'reason' => 'wrong room',
            ])
            ->assertStatus(422);

        // Missing reason is a validation error.
        $this->actingAs($reporter)
            ->postJson('/api/v1/reports', [
                'room_id' => $room->id,
                'target_user_id' => $host->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }
}

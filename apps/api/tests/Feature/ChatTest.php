<?php

namespace Tests\Feature;

use App\Events\MessageDeleted;
use App\Events\MessageSent;
use App\Models\Message;
use App\Models\Room;
use App\Models\RoomBan;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Milestone 4 (PRD 4.6): chat — 300 chars, 1 per 2 s / 20 per min, profanity
 * mask, link block for accounts under 24 h, silent focus, member/host/admin
 * deletes, 200-message prune, banned readers.
 */
class ChatTest extends TestCase
{
    use RefreshDatabase;

    private function makeRoom(User $host, array $overrides = []): Room
    {
        return Room::create(array_merge([
            'name' => 'Chat Room',
            'type' => Room::TYPE_PUBLIC,
            'host_id' => $host->id,
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

    /**
     * The throttle middleware hashes (limiter name + by()) into one cache
     * key; tests that are not ABOUT the burst window clear it between
     * sends so the 1-per-2 s limit never masks the behaviour under test.
     */
    private function clearChatBurst(int $userId): void
    {
        RateLimiter::clear(md5('chat-send'.'chat-burst|'.$userId));
    }

    public function test_member_sends_a_message_and_it_broadcasts(): void
    {
        Event::fake([MessageSent::class]);

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        $response = $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/messages", [
            'body' => 'hello room',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message.body', 'hello room')
            ->assertJsonPath('message.user_id', $host->id);

        $this->assertDatabaseHas('messages', [
            'room_id' => $room->id,
            'user_id' => $host->id,
            'body' => 'hello room',
        ]);

        Event::assertDispatched(MessageSent::class, fn (MessageSent $e): bool => $e->message->room_id === $room->id && $e->message->body === 'hello room');
    }

    public function test_message_body_cannot_exceed_300_characters(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => str_repeat('a', 301)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_blank_message_is_rejected(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_second_message_within_two_seconds_is_throttled(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'one'])
            ->assertCreated();

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'two'])
            ->assertStatus(429);

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_twenty_first_message_in_a_minute_is_throttled(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        // Twenty sends, each clearing only the burst window so the
        // per-minute counter is what actually fills up.
        for ($i = 0; $i < 20; $i++) {
            $this->clearChatBurst((int) $host->id);

            $this->actingAs($host)
                ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => "msg {$i}"])
                ->assertCreated();
        }

        $this->clearChatBurst((int) $host->id);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'one too many'])
            ->assertStatus(429);

        $this->assertDatabaseCount('messages', 20);
    }

    public function test_non_member_cannot_read_or_send_messages(): void
    {
        $host = User::factory()->create();
        $outsider = User::factory()->create();
        $room = $this->makeRoom($host);

        $this->actingAs($outsider)
            ->getJson("/api/v1/rooms/{$room->id}/messages")
            ->assertStatus(403);

        $this->actingAs($outsider)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'sneaky'])
            ->assertStatus(403);
    }

    public function test_silent_focus_mutes_chat_during_the_focus_phase(): void
    {
        Event::fake([MessageSent::class]);

        $host = User::factory()->create();
        $room = $this->makeRoom($host, ['silent_focus' => true]);
        $this->join($room, $host);

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/start")->assertOk();

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'talk during focus'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Chat is muted during focus (silent focus is on). Reactions are still allowed.');

        // Break phases are chatable again — and the throttled 403 above
        // still consumed the burst slot, so clear it before the next send.
        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/skip")->assertOk();
        $this->clearChatBurst((int) $host->id);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'talk during break'])
            ->assertCreated();

        Event::assertDispatched(MessageSent::class, fn (MessageSent $e): bool => $e->message->body === 'talk during break');
    }

    public function test_silent_focus_off_allows_chat_during_focus(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host, ['silent_focus' => false]);
        $this->join($room, $host);

        $this->actingAs($host)->postJson("/api/v1/rooms/{$room->id}/timer/start")->assertOk();

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'chatty focus'])
            ->assertCreated();
    }

    public function test_muted_member_cannot_send_until_unmuted(): void
    {
        $host = User::factory()->create();
        $mate = User::factory()->create();
        $room = $this->makeRoom($host);
        $member = $this->join($room, $mate);
        $member->update(['is_muted' => true]);

        $this->actingAs($mate)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'let me in'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You are muted in this room.');

        $member->update(['is_muted' => false]);
        $this->clearChatBurst((int) $mate->id);

        $this->actingAs($mate)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'thanks'])
            ->assertCreated();
    }

    public function test_profanity_is_masked_before_storing(): void
    {
        config(['profanity.words' => ['badword']]);

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'What a BadWord day'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'What a *** day');
    }

    public function test_account_under_24_hours_old_cannot_post_links(): void
    {
        $newbie = User::factory()->create();
        $veteran = User::factory()->create(['created_at' => now()->subDays(2)]);
        $room = $this->makeRoom($newbie);
        $this->join($room, $newbie);
        $this->join($room, $veteran);

        $this->actingAs($newbie)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'see https://example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->actingAs($veteran)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'see https://example.com'])
            ->assertCreated();
    }

    public function test_author_can_delete_their_own_message(): void
    {
        Event::fake([MessageDeleted::class]);

        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        $sent = $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'oops'])
            ->assertCreated()
            ->json('message');

        $this->actingAs($host)
            ->deleteJson("/api/v1/rooms/{$room->id}/messages/{$sent['id']}")
            ->assertOk();

        $this->assertSoftDeleted('messages', ['id' => $sent['id']]);

        Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $e): bool => $e->roomId === $room->id && $e->messageId === (int) $sent['id']);
    }

    public function test_host_can_delete_a_members_message_but_another_member_cannot(): void
    {
        $host = User::factory()->create();
        $author = User::factory()->create();
        $bystander = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $author);
        $this->join($room, $bystander);

        $sent = $this->actingAs($author)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'spicy take'])
            ->assertCreated()
            ->json('message');

        $this->actingAs($bystander)
            ->deleteJson("/api/v1/rooms/{$room->id}/messages/{$sent['id']}")
            ->assertStatus(403);

        $this->actingAs($host)
            ->deleteJson("/api/v1/rooms/{$room->id}/messages/{$sent['id']}")
            ->assertOk();

        $this->assertSoftDeleted('messages', ['id' => $sent['id']]);
    }

    public function test_admin_can_delete_any_message(): void
    {
        $host = User::factory()->create();
        $author = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $room = $this->makeRoom($host);
        $this->join($room, $author);

        $sent = $this->actingAs($author)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'rule break'])
            ->assertCreated()
            ->json('message');

        $this->actingAs($admin)
            ->deleteJson("/api/v1/rooms/{$room->id}/messages/{$sent['id']}")
            ->assertOk();

        $this->assertSoftDeleted('messages', ['id' => $sent['id']]);
    }

    public function test_deleted_messages_vanish_from_the_listing(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $this->join($room, $host);

        $first = $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'stays'])
            ->json('message');

        $this->clearChatBurst((int) $host->id);
        $second = $this->actingAs($host)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'goes'])
            ->json('message');

        $this->actingAs($host)
            ->deleteJson("/api/v1/rooms/{$room->id}/messages/{$second['id']}")
            ->assertOk();

        $listing = $this->actingAs($host)
            ->getJson("/api/v1/rooms/{$room->id}/messages")
            ->assertOk()
            ->json('messages');

        $this->assertCount(1, $listing);
        $this->assertSame('stays', $listing[0]['body']);
        $this->assertSame($first['id'], $listing[0]['id']);
    }

    public function test_prune_keeps_only_the_newest_200_messages_per_room(): void
    {
        $host = User::factory()->create();
        $room = $this->makeRoom($host);
        $small = $this->makeRoom($host, ['name' => 'Small Chat']);

        $now = now()->toDateTimeString();
        $rows = [];
        for ($i = 0; $i < 205; $i++) {
            $rows[] = [
                'room_id' => $room->id,
                'user_id' => $host->id,
                'body' => "message {$i}",
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Message::insert($rows);

        $smallRows = [];
        for ($i = 0; $i < 5; $i++) {
            $smallRows[] = [
                'room_id' => $small->id,
                'user_id' => $host->id,
                'body' => "small {$i}",
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Message::insert($smallRows);

        $this->artisan('messages:prune')->assertExitCode(0);

        $this->assertSame(200, Message::where('room_id', $room->id)->count());
        $this->assertSame('message 204', Message::where('room_id', $room->id)->orderByDesc('id')->value('body'));
        // A room under the cap is untouched.
        $this->assertSame(5, Message::where('room_id', $small->id)->count());
    }

    public function test_banned_user_cannot_read_or_send_messages(): void
    {
        $host = User::factory()->create();
        $banned = User::factory()->create();
        $room = $this->makeRoom($host);

        // A ban removes the membership row (ModerationService does both).
        $this->join($room, $banned)->delete();
        RoomBan::create(['room_id' => $room->id, 'user_id' => $banned->id, 'banned_by' => $host->id]);

        $this->actingAs($banned)
            ->getJson("/api/v1/rooms/{$room->id}/messages")
            ->assertStatus(403)
            ->assertJsonPath('message', 'You are banned from this room.');

        $this->actingAs($banned)
            ->postJson("/api/v1/rooms/{$room->id}/messages", ['body' => 'let me back'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You are banned from this room.');
    }
}

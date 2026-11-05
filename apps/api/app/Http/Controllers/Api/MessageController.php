<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageDeleted;
use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Room;
use App\Services\PresenceService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * PRD 4.6: lightweight room chat — 300 characters, 1 per 2 s / 20 per min,
 * profanity mask, link block for accounts under 24 h, last 200 kept, and
 * host "silent focus" mutes everyone during focus phases (reactions only).
 */
class MessageController extends Controller
{
    /**
     * PRD 4.6: the last 200 messages, oldest first (clients append new ones).
     * Members only — chat is not visible to lurkers of a public room.
     */
    public function index(Request $request, Room $room): JsonResponse
    {
        if ($room->isBanned((int) $request->user()->id)) {
            throw new HttpResponseException(response()->json([
                'message' => 'You are banned from this room.',
            ], 403));
        }

        $this->requireMember($request, $room);

        $messages = Message::query()
            ->where('room_id', $room->id)
            ->orderByDesc('id')
            ->limit(200)
            ->with('user:id,name,avatar_emoji,avatar_color')
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'messages' => $messages->map(fn (Message $m) => $this->payload($m))->all(),
            'server_now_ms' => (int) now()->getTimestampMs(),
        ]);
    }

    public function store(Request $request, Room $room, PresenceService $presence): JsonResponse
    {
        $user = $request->user();

        if ($room->archived_at !== null) {
            throw new HttpResponseException(response()->json([
                'message' => 'This room has been closed.',
            ], 404));
        }

        if ($room->isBanned((int) $user->id)) {
            throw new HttpResponseException(response()->json([
                'message' => 'You are banned from this room.',
            ], 403));
        }

        $member = $room->members()->where('user_id', $user->id)->first();

        if ($member === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'Join the room before chatting.',
            ], 403));
        }

        if ($member->is_muted) {
            throw new HttpResponseException(response()->json([
                'message' => 'You are muted in this room.',
            ], 403));
        }

        // PRD 4.6: silent focus auto-mutes everyone during a focus phase —
        // host included, only reactions get through.
        if ($room->silent_focus && $presence->resolvePhase($room) === 'focus') {
            throw new HttpResponseException(response()->json([
                'message' => 'Chat is muted during focus (silent focus is on). Reactions are still allowed.',
            ], 403));
        }

        $request->merge(['body' => trim((string) $request->input('body'))]);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:300'],
        ]);

        $body = $this->maskProfanity($data['body']);

        // PRD 4.6: link blocking for new accounts (under 24 hours old).
        if ($this->containsLink($body) && $user->created_at->gt(now()->subDay())) {
            throw ValidationException::withMessages([
                'body' => 'New accounts cannot post links for their first 24 hours.',
            ]);
        }

        $message = Message::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);

        $message->load('user:id,name,avatar_emoji,avatar_color');
        event(new MessageSent($message));

        return response()->json([
            'message' => $this->payload($message),
            'server_now_ms' => (int) now()->getTimestampMs(),
        ], 201);
    }

    /**
     * PRD 4.6: the author, the room host and admins may delete. Soft delete
     * keeps a tombstone; MessageDeleted makes every client drop the bubble.
     */
    public function destroy(Request $request, Room $room, int $messageId): JsonResponse
    {
        $message = Message::query()
            ->where('room_id', $room->id)
            ->where('id', $messageId)
            ->firstOrFail();

        $user = $request->user();
        $allowed = (int) $message->user_id === (int) $user->id
            || $room->isHost($user)
            || $user->isAdmin();

        if (! $allowed) {
            return response()->json(['message' => 'Only the author, the host or an admin can delete a message.'], 403);
        }

        $message->delete();
        event(new MessageDeleted($room->id, (int) $message->id));

        return response()->json(['message' => 'Message deleted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Message $message): array
    {
        return [
            'id' => (int) $message->id,
            'room_id' => (int) $message->room_id,
            'user_id' => (int) $message->user_id,
            'name' => $message->user?->name,
            'avatar_emoji' => $message->user?->avatar_emoji,
            'avatar_color' => $message->user?->avatar_color,
            'body' => $message->body,
            'created_at_ms' => (int) $message->created_at->getTimestampMs(),
        ];
    }

    private function requireMember(Request $request, Room $room): void
    {
        $member = $room->members()->where('user_id', $request->user()->id)->exists();

        if (! $member) {
            throw new HttpResponseException(response()->json([
                'message' => 'Join the room to read its chat.',
            ], 403));
        }
    }

    /**
     * Configurable word list (config/profanity.php): matched words are
     * masked, not rejected — a filter, not a gate (decision logged).
     */
    private function maskProfanity(string $body): string
    {
        $words = config('profanity.words', []);

        foreach ($words as $word) {
            $word = trim((string) $word);

            if ($word === '') {
                continue;
            }

            $body = (string) preg_replace(
                '/\b'.preg_quote($word, '/').'\b/iu',
                '***',
                $body,
            );
        }

        return $body;
    }

    private function containsLink(string $body): bool
    {
        return (bool) preg_match('~(?:https?://|www\.)\S+~i', $body);
    }
}

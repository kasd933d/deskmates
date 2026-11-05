<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PRD 7.2: `MessageSent` carries message id, user, text and time. Broadcast
 * on the presence channel: chat is a member activity, so lurkers of a public
 * room and banned users (rejected by channel auth) never see it live — the
 * same gate the GET /messages endpoint enforces.
 */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Fire the broadcast only once the message row has committed. */
    public bool $afterCommit = true;

    public function __construct(public Message $message) {}

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => (int) $this->message->id,
            'room_id' => (int) $this->message->room_id,
            'user_id' => (int) $this->message->user_id,
            'name' => $this->message->user?->name,
            'avatar_emoji' => $this->message->user?->avatar_emoji,
            'avatar_color' => $this->message->user?->avatar_color,
            'body' => $this->message->body,
            'created_at_ms' => (int) $this->message->created_at->getTimestampMs(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function broadcastOn(): array
    {
        return ['presence-room.'.$this->message->room_id];
    }
}

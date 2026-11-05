<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PRD 7.2: `MessageDeleted` — the room drops the bubble (host/admin/author
 * moderation, PRD 4.6). Presence channel, same gate as MessageSent.
 */
class MessageDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Fire the broadcast only once the delete has committed. */
    public bool $afterCommit = true;

    public function __construct(
        public int $roomId,
        public int $messageId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'message_id' => $this->messageId,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function broadcastOn(): array
    {
        return ['presence-room.'.$this->roomId];
    }
}

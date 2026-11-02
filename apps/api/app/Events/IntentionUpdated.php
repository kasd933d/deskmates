<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PRD 7.2: `IntentionUpdated` on the room channel with user id and text —
 * every viewer of the room sees the new intention live.
 */
class IntentionUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Fire the broadcast only once the intention write has committed. */
    public bool $afterCommit = true;

    public function __construct(
        public int $roomId,
        public int $userId,
        public ?string $intention,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'user_id' => $this->userId,
            'intention' => $this->intention,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function broadcastOn(): array
    {
        return ['room.'.$this->roomId];
    }
}

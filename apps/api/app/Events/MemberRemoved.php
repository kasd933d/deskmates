<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A member left the room — voluntarily (leave), by policy (joined a second
 * room) or by the stale heartbeat reaper. Broadcast on the presence channel
 * (PRD 7.2: presence-room.{id} carries member join/leave); emitted after the
 * membership change commits (PRD instruction #5).
 */
class MemberRemoved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Fire the broadcast only once the membership change has committed. */
    public bool $afterCommit = true;

    public function __construct(
        public int $roomId,
        public int $userId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'user_id' => $this->userId,
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

<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Visibility-driven status transition (focusing / break / away).
 *
 * Emitted only when the AWAY state flips, i.e. when the heartbeat reports a
 * tab hidden for over 2 minutes or visible again. The focusing/break half of
 * a status is a pure function of the room phase, which every client already
 * knows from RoomPhaseChanged — no per-member event needed for that.
 *
 * Broadcast on the presence channel; delivered after commit.
 */
class MemberStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Fire the broadcast only once the status change has committed. */
    public bool $afterCommit = true;

    public function __construct(
        public int $roomId,
        public int $userId,
        public string $status,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'user_id' => $this->userId,
            'status' => $this->status,
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

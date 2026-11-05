<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PRD 4.6/7.2 (extra event, decision in docs/decisions.md): a host muted or
 * unmuted a member — the grid's 🔇 badge and the member's composer must flip
 * live, which MemberStatusChanged / RoomSettingsUpdated cannot express.
 */
class MemberMuted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Fire the broadcast only once the membership change has committed. */
    public bool $afterCommit = true;

    public function __construct(
        public int $roomId,
        public int $userId,
        public bool $muted,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'user_id' => $this->userId,
            'is_muted' => $this->muted,
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

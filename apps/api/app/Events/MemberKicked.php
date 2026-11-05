<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PRD 7.2: `MemberKicked` on the member's private channel — the only way the
 * kicked client learns its verdict (a presence MemberRemoved alone cannot
 * tell "you were kicked" from "you left"). `reason` distinguishes a kick
 * (may rejoin) from a ban (may not).
 */
class MemberKicked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Fire the broadcast only once the moderation change has committed. */
    public bool $afterCommit = true;

    public function __construct(
        public int $userId,
        public int $roomId,
        public string $roomSlug,
        public string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'room_slug' => $this->roomSlug,
            'reason' => $this->reason,
            'server_now_ms' => (int) now()->getTimestampMs(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function broadcastOn(): array
    {
        // channels.php pattern `user.{id}` — echo.private('user.{id}')
        // subscribes to the wire name `private-user.{id}` (PRD 7.2).
        return ['private-user.'.$this->userId];
    }
}

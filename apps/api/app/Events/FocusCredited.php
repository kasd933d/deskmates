<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PRD 5 session-end flow: focus phase ends → credit job runs → "+X min
 * focused" toast → check-in prompt → stats update. This is the only signal
 * that can carry "you were credited" to the right client — it rides the
 * member's private channel (same pattern as MemberKicked, PRD 7.2).
 *
 * Only fired for sessions that actually exist (attendance reached the
 * 5-minute floor and the daily cap had room), so the toast always links to a
 * real focus_session the check-in prompt can PATCH.
 */
class FocusCredited implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Never beat the transaction that created the session row. */
    public bool $afterCommit = true;

    public function __construct(
        public int $userId,
        public int $sessionId,
        public int $minutes,
        public int $roomId,
        public string $localDate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'minutes' => $this->minutes,
            'room_id' => $this->roomId,
            'local_date' => $this->localDate,
            'server_now_ms' => (int) now()->getTimestampMs(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function broadcastOn(): array
    {
        // channels.php pattern `user.{id}` → wire name `private-user.{id}`,
        // already authorized since Milestone 4.
        return ['private-user.'.$this->userId];
    }
}

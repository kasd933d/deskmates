<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PRD 7.2: `RoomSettingsUpdated` on the room channel with the new config and
 * host id. Emitted for host edits (M4) and for host transfer — when the host
 * leaves, the new host must see their controls appear without a refresh.
 */
class RoomSettingsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Fire the broadcast only once the settings change has committed. */
    public bool $afterCommit = true;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public int $roomId,
        public int $hostId,
        public array $config,
    ) {}

    public static function fromRoom(Room $room): self
    {
        return new self(
            $room->id,
            (int) $room->host_id,
            $room->only([
                'name',
                'theme',
                'focus_minutes',
                'short_break_minutes',
                'long_break_minutes',
                'long_break_every',
                'max_members',
                'silent_focus',
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'host_id' => $this->hostId,
            ...$this->config,
            'server_now_ms' => (int) now()->getTimestampMs(),
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

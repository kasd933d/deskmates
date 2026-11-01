<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to everyone in a room when its phase changes. On the public
 * room channel for v1; presence/private channel auth arrives with M3/M4.
 */
class RoomPhaseChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array{phase: string, phase_ends_at_ms: int|null, phase_started_at_ms: int|null, cycle_index: int, phase_version?: int}  $state
     */
    public function __construct(
        public int $roomId,
        public string $roomSlug,
        public array $state,
        public int $serverNowMs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'room_slug' => $this->roomSlug,
            'phase' => $this->state['phase'],
            'phase_started_at_ms' => $this->state['phase_started_at_ms'],
            'phase_ends_at_ms' => $this->state['phase_ends_at_ms'],
            'cycle_index' => $this->state['cycle_index'],
            'phase_version' => $this->state['phase_version'] ?? null,
            'server_now_ms' => $this->serverNowMs,
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

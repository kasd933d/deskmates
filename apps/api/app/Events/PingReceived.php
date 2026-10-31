<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PingReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $message,
        public readonly int $clientTimeMs = 0,
    ) {}

    /**
     * The channels the event should be broadcast on.
     *
     * @return array<int, string>
     */
    public function broadcastOn(): array
    {
        return ['ping'];
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'client_time_ms' => $this->clientTimeMs,
            'server_now_ms' => (int) now()->getTimestampMs(),
        ];
    }
}

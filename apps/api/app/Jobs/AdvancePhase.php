<?php

namespace App\Jobs;

use App\Models\Room;
use App\Services\RoomClockService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Advances a user room's phase when the current one ends.
 *
 * Idempotent by design: if `phase_version` has changed since the job was
 * queued (a host skipped, paused, or another job already fired), the job
 * does nothing. A lazy fallback (RoomClockService::advanceIfExpired) also
 * advances on the next request if the queue was down.
 */
class AdvancePhase implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $roomId,
        public int $expectedVersion,
    ) {}

    public function handle(RoomClockService $clock): void
    {
        $room = Room::find($this->roomId);

        if (! $room || $room->isAlwaysOn()) {
            return;
        }

        if ($room->phase_version !== $this->expectedVersion) {
            // The phase already changed after this job was queued.
            return;
        }

        if ($room->phase_ends_at === null || $room->phase_ends_at->isFuture()) {
            return;
        }

        $clock->transition($room, 'skip');
    }
}

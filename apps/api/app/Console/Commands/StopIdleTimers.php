<?php

namespace App\Console\Commands;

use App\Models\Room;
use App\Services\RoomClockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PRD 4.2: "If the room is empty for 10 minutes, the timer stops and the
 * room goes `idle`." Runs every 5 minutes with ArchiveEmptyRooms.
 */
class StopIdleTimers extends Command
{
    protected $signature = 'rooms:stop-idle-timers';

    protected $description = 'Stop timers in user rooms that have been empty for 10+ minutes';

    public function handle(RoomClockService $clock): int
    {
        $cutoff = now()->subMinutes(10);

        // Empty = no members left. last_active_at tracks the last join,
        // leave, or heartbeat (presence hardening lands in M3).
        $ids = Room::query()
            ->whereNotIn('type', [Room::TYPE_ALWAYS_ON])
            ->whereNull('archived_at')
            ->whereNotIn('id', function ($query) {
                $query->select('room_id')->from('room_members');
            })
            ->where('phase', '!=', 'idle')
            ->where(function ($query) use ($cutoff) {
                $query->where('last_active_at', '<', $cutoff)
                    ->orWhereNull('last_active_at');
            })
            ->pluck('id');

        $stopped = 0;

        if ($ids->isNotEmpty()) {
            $stopped = Room::query()->whereIn('id', $ids)->update([
                'phase' => 'idle',
                'phase_started_at' => null,
                'phase_ends_at' => null,
                'phase_version' => DB::raw('phase_version + 1'),
            ]);

            // Re-read so sync sees `idle`: it closes the interrupted phase's
            // history row and credits whatever members present before the
            // stop earned (PRD 4.8) instead of waiting out the original end.
            Room::query()
                ->whereIn('id', $ids)
                ->get()
                ->each(fn (Room $room) => $clock->syncPhaseHistory($room));
        }

        $this->info("Stopped {$stopped} idle timer(s).");

        return self::SUCCESS;
    }
}

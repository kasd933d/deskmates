<?php

namespace App\Console\Commands;

use App\Models\Room;
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

    public function handle(): int
    {
        $cutoff = now()->subMinutes(10);

        // Empty = no members left. last_active_at tracks the last join,
        // leave, or heartbeat (presence hardening lands in M3).
        $stopped = Room::query()
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
            ->update([
                'phase' => 'idle',
                'phase_started_at' => null,
                'phase_ends_at' => null,
                'phase_version' => DB::raw('phase_version + 1'),
            ]);

        $this->info("Stopped {$stopped} idle timer(s).");

        return self::SUCCESS;
    }
}

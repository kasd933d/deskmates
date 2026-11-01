<?php

namespace App\Console\Commands;

use App\Models\Room;
use Illuminate\Console\Command;

/**
 * PRD 4.2: "Empty user rooms are archived after 24 hours." Runs every
 * 5 minutes with StopIdleTimers.
 */
class ArchiveEmptyRooms extends Command
{
    protected $signature = 'rooms:archive-empty';

    protected $description = 'Archive user rooms that have been empty for 24 hours';

    public function handle(): int
    {
        $cutoff = now()->subDay();

        $archived = Room::query()
            ->whereNotIn('type', [Room::TYPE_ALWAYS_ON])
            ->whereNull('archived_at')
            ->whereNotIn('id', function ($query) {
                $query->select('room_id')->from('room_members');
            })
            ->where(function ($query) use ($cutoff) {
                $query->where('last_active_at', '<', $cutoff)
                    ->orWhereNull('last_active_at');
            })
            ->update(['archived_at' => now()]);

        $this->info("Archived {$archived} empty room(s).");

        return self::SUCCESS;
    }
}

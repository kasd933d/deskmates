<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PRD 4.6/7.3: "keeps the last 200 messages per room; older ones are
 * pruned" — nightly (PRD 7.3 schedules PruneOldMessages).
 */
class PruneOldMessages extends Command
{
    protected $signature = 'messages:prune {--keep=200 : Messages to keep per room}';

    protected $description = 'Prune each room down to its newest messages';

    public function handle(): int
    {
        $keep = max(1, (int) $this->option('keep'));
        $pruned = 0;

        // Rooms that actually overflow: cheap pre-filter, then per-room
        // cut below the pivot id (portable across SQLite and MySQL).
        $roomIds = Message::query()
            ->select('room_id')
            ->groupBy('room_id')
            ->havingRaw('COUNT(*) > ?', [$keep])
            ->pluck('room_id');

        foreach ($roomIds as $roomId) {
            // pluck (not value): value() delegates to first(), which overrides
            // take($keep) with limit 1 and would return the NEWEST id — the
            // delete would then wipe the room down to a single message.
            $pivot = Message::query()
                ->where('room_id', $roomId)
                ->orderByDesc('id')
                ->take($keep)
                ->pluck('id')
                ->last();

            if ($pivot === null) {
                continue;
            }

            // Hard delete via the query builder: Eloquent's soft-delete
            // scope would turn this into an UPDATE and never free the rows.
            $pruned += DB::table('messages')
                ->where('room_id', $roomId)
                ->where('id', '<', $pivot)
                ->delete();
        }

        $this->info("Pruned {$pruned} message(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\PhaseAttendance;
use Illuminate\Console\Command;

/**
 * PRD 7.3 nightly `PruneOldAttendance`: attendance exists only to feed the
 * credit job, so rows for phases credited more than N days ago are dead
 * weight. Uncredited (stalled) phases keep theirs — the nightly rollup still
 * has something to credit them from.
 */
class PruneOldAttendance extends Command
{
    protected $signature = 'focus:prune-attendance {--days=30 : Keep attendance this many days after the phase was credited}';

    protected $description = 'Delete attendance rows for long-credited phases (PRD 7.3)';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));

        $deleted = PhaseAttendance::query()
            ->whereIn('phase_id', function ($query) use ($cutoff) {
                $query->select('id')
                    ->from('room_phases')
                    ->whereNotNull('credited_at')
                    ->where('ends_at', '<', $cutoff);
            })
            ->delete();

        $this->info("Pruned {$deleted} attendance row(s).");

        return self::SUCCESS;
    }
}

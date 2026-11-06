<?php

namespace App\Console\Commands;

use App\Jobs\CreditFocusForPhase;
use App\Models\RoomPhase;
use App\Models\UserDailyFocus;
use App\Services\RoomClockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PRD 7.3 nightly `RollupDailyFocus`.
 *
 * Two duties:
 *  1. Repair: re-dispatch CreditFocusForPhase for focus windows whose job was
 *     lost (queue outage, exhausted tries). Credit runs before aggregation,
 *     so tonight's rebuild picks the late sessions up too.
 *  2. Rebuild: recompute every user_daily_focus row from focus_sessions —
 *     the authoritative credited record — and drop rows no session backs.
 */
class RollupDailyFocus extends Command
{
    protected $signature = 'focus:rollup {--repair-limit=200 : Max stalled phases to re-dispatch per night}';

    protected $description = 'Rebuild user_daily_focus from focus_sessions and repair stalled credits (PRD 7.3)';

    public function handle(): int
    {
        $repaired = 0;

        $stalled = RoomPhase::query()
            ->whereNull('credited_at')
            ->where('type', RoomClockService::PHASE_FOCUS)
            ->where('ends_at', '<=', now()->subHour())
            ->orderByDesc('ends_at')
            ->limit(max(0, (int) $this->option('repair-limit')))
            ->pluck('id');

        foreach ($stalled as $phaseId) {
            CreditFocusForPhase::dispatch((int) $phaseId);
            $repaired++;
        }

        $aggregates = DB::table('focus_sessions')
            ->groupBy('user_id', 'local_date')
            ->selectRaw('user_id, local_date as date, SUM(minutes) as minutes, COUNT(*) as sessions')
            ->get();

        foreach ($aggregates->chunk(500) as $chunk) {
            UserDailyFocus::upsert(
                $chunk->map(fn ($row) => [
                    'user_id' => $row->user_id,
                    'date' => $row->date,
                    'minutes' => (int) $row->minutes,
                    'sessions' => (int) $row->sessions,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all(),
                ['user_id', 'date'],
                ['minutes', 'sessions', 'updated_at'],
            );
        }

        $orphans = UserDailyFocus::query()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('focus_sessions')
                    ->whereColumn('focus_sessions.user_id', 'user_daily_focus.user_id')
                    ->whereColumn('focus_sessions.local_date', 'user_daily_focus.date');
            })
            ->delete();

        $this->info("Repaired {$repaired} stalled phase(s), rebuilt {$aggregates->count()} daily row(s), removed {$orphans} orphan(s).");

        return self::SUCCESS;
    }
}

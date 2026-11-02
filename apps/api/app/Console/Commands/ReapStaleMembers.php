<?php

namespace App\Console\Commands;

use App\Models\RoomMember;
use App\Services\PresenceService;
use Illuminate\Console\Command;

/**
 * PRD 4.4/7.3: "A member is considered gone after 60 seconds without a
 * heartbeat (reaper job every 30 s removes stale members and broadcasts
 * leave)." Host transfer rides along inside PresenceService::removeMember.
 */
class ReapStaleMembers extends Command
{
    protected $signature = 'presence:reap-stale {--threshold=60 : Seconds without a heartbeat before a member is gone}';

    protected $description = 'Remove members whose last heartbeat is older than the threshold';

    public function handle(PresenceService $presence): int
    {
        $threshold = (int) $this->option('threshold');
        $cutoff = now()->subSeconds($threshold);

        $stale = RoomMember::query()
            ->where(function ($query) use ($cutoff) {
                $query->where('last_seen_at', '<', $cutoff)
                    ->orWhereNull('last_seen_at');
            })
            ->get();

        foreach ($stale as $member) {
            $presence->removeMember($member);
        }

        $this->info("Reaped {$stale->count()} stale member(s).");

        return self::SUCCESS;
    }
}

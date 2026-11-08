<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\ScheduledSession;
use App\Services\PushService;
use Illuminate\Console\Command;

/**
 * PRD 4.10/7.3: a Web Push reminder 10 minutes before a scheduled session
 * the user RSVP'd to. The window is "starts within the next 10 minutes"
 * rather than "exactly 10 minutes from now", so a sweep that missed its
 * slot (downtime) still reminds instead of skipping the session; the
 * `reminded_at` marker keeps the fan-out exactly once.
 */
class SendSessionReminders extends Command
{
    protected $signature = 'sessions:remind';

    protected $description = 'Push a reminder to RSVP members 10 minutes before each session';

    public function handle(PushService $push): int
    {
        $sessions = ScheduledSession::query()
            ->where('status', ScheduledSession::STATUS_SCHEDULED)
            ->whereNull('reminded_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addMinutes(10))
            ->with(['room', 'rsvps'])
            ->get();

        $delivered = 0;

        foreach ($sessions as $session) {
            $userIds = $session->rsvps->pluck('user_id');
            $room = $session->room;

            if ($room !== null && $userIds->isNotEmpty()) {
                $subscriptions = PushSubscription::query()->whereIn('user_id', $userIds)->get();

                if ($subscriptions->isNotEmpty()) {
                    $minutes = max(1, (int) ceil(now()->diffInMinutes($session->starts_at)));

                    $delivered += $push->sendAll(
                        $subscriptions,
                        title: $session->title,
                        body: "Starts in {$minutes} min in {$room->name}.",
                        url: '/room/'.$room->slug,
                    );
                }
            }

            // Mark even when nothing was sent: a failed delivery must not
            // re-fire every minute, and subscribers without a subscription
            // row (or without RSVPs) are done being considered.
            $session->update(['reminded_at' => now()]);
        }

        $this->info("Reminded {$sessions->count()} session(s); {$delivered} push(es) delivered.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Events\RoomSettingsUpdated;
use App\Models\ScheduledSession;
use App\Services\RoomClockService;
use Illuminate\Console\Command;
use Throwable;

/**
 * PRD 4.11/7.3: "at start time the room timer starts automatically." The
 * scheduled → started status flip is the claim, so overlapping or
 * repeated sweeps can never double-start a session; a transient failure
 * un-claims it for the next minute's retry.
 */
class StartScheduledSessions extends Command
{
    protected $signature = 'sessions:start';

    protected $description = 'Start scheduled sessions whose start time has arrived';

    public function handle(RoomClockService $clock): int
    {
        $started = 0;
        $cancelled = 0;

        ScheduledSession::query()
            ->where('status', ScheduledSession::STATUS_SCHEDULED)
            ->where('starts_at', '<=', now())
            ->orderBy('starts_at')
            ->get()
            ->each(function (ScheduledSession $session) use ($clock, &$started, &$cancelled) {
                $claimed = ScheduledSession::query()
                    ->whereKey($session->id)
                    ->where('status', ScheduledSession::STATUS_SCHEDULED)
                    ->update(['status' => ScheduledSession::STATUS_STARTED]);

                if ($claimed === 0) {
                    return; // another sweep won the race
                }

                $room = $session->room;

                if ($room === null || $room->archived_at !== null) {
                    // The room closed between booking and start time.
                    ScheduledSession::query()
                        ->whereKey($session->id)
                        ->update(['status' => ScheduledSession::STATUS_CANCELLED]);
                    $cancelled++;

                    return;
                }

                try {
                    // Apply the booking's timer config (PRD 4.11), tell the
                    // room's clients, then start. transition('start') is a
                    // no-op if somebody already started the timer by hand.
                    $room->fill($session->timerConfig())->save();
                    event(RoomSettingsUpdated::fromRoom($room));

                    $clock->transition($room, 'start');
                    $started++;
                } catch (Throwable $exception) {
                    // Un-claim: a transient failure must not eat the booking.
                    ScheduledSession::query()
                        ->whereKey($session->id)
                        ->where('status', ScheduledSession::STATUS_STARTED)
                        ->update(['status' => ScheduledSession::STATUS_SCHEDULED]);

                    report($exception);
                }
            });

        $this->info("Started {$started} scheduled session(s); {$cancelled} cancelled.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\RoomMember;
use App\Services\PushService;
use App\Services\RoomClockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PRD 4.10: Web Push when a phase changes — to the members currently in
 * the room who opted in. Whether the notification actually appears is the
 * service worker's call: it suppresses the toast while a DeskMates tab is
 * visible (the in-tab chime covers that case), so this job only decides
 * who *could* be told.
 *
 * ShouldQueueAfterCommit: with the database queue the fan-out must see the
 * committed phase change; with the local sync queue it runs on the same
 * connection anyway.
 */
class SendPhasePush implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $roomId,
        public string $roomSlug,
        public string $roomName,
        public string $phase,
    ) {}

    public function handle(PushService $push): void
    {
        $memberIds = RoomMember::query()->where('room_id', $this->roomId)->pluck('user_id');

        if ($memberIds->isEmpty()) {
            return;
        }

        $subscriptions = PushSubscription::query()->whereIn('user_id', $memberIds)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        [$title, $body] = $this->phase === RoomClockService::PHASE_FOCUS
            ? [$this->roomName, 'Focus time started — come focus with us.']
            : [$this->roomName, 'Break time — stretch and recharge.'];

        $push->sendAll($subscriptions, $title, $body, '/room/'.$this->roomSlug, backgroundOnly: true);
    }
}

<?php

namespace App\Console\Commands;

use App\Events\RoomPhaseChanged;
use App\Jobs\CreditFocusForPhase;
use App\Jobs\SendPhasePush;
use App\Models\Room;
use App\Services\RoomClockService;
use Illuminate\Console\Command;

/**
 * Confirms always-on phase changes with a broadcast (PRD 4.3): clients
 * compute the deterministic clock locally, this scheduled sweep tells every
 * connected client when a phase actually flips.
 *
 * The `rooms.phase` / `phase_ends_at` columns act purely as a "last
 * broadcast" marker for always-on rooms — never as timer state.
 */
class BroadcastAlwaysOnPhases extends Command
{
    protected $signature = 'rooms:broadcast-always-on';

    protected $description = 'Broadcast deterministic phase changes for always-on rooms';

    public function handle(RoomClockService $clock): int
    {
        $nowMs = (int) now()->getTimestampMs();
        $changed = 0;

        Room::query()
            ->where('type', Room::TYPE_ALWAYS_ON)
            ->whereNull('archived_at')
            ->each(function (Room $room) use ($clock, $nowMs, &$changed) {
                $state = $clock->alwaysOnPhase($room, $nowMs);

                if ($room->phase === $state['phase']
                    && $room->phase_ends_at?->getTimestampMs() === $state['phase_ends_at_ms']) {
                    return;
                }

                // The window that just ended, computed from one millisecond
                // before the new one starts (contiguous for a steady
                // scheduler; whatever the clock says for a delayed one).
                $ended = $clock->alwaysOnPhase($room, $state['phase_started_at_ms'] - 1);

                $room->forceFill([
                    'phase' => $state['phase'],
                    'phase_started_at' => date('Y-m-d H:i:s', intdiv($state['phase_started_at_ms'], 1000)),
                    'phase_ends_at' => date('Y-m-d H:i:s', intdiv($state['phase_ends_at_ms'], 1000)),
                    'cycle_index' => $state['cycle_index'],
                ])->saveQuietly();

                event(new RoomPhaseChanged($room->id, $room->slug, $state, $nowMs));
                $changed++;

                // PRD 4.10: Web Push on the flip for always-on rooms too —
                // same fan-out as user rooms (members who opted in).
                if ($state['phase'] !== RoomClockService::PHASE_IDLE) {
                    SendPhasePush::dispatch(
                        (int) $room->id,
                        $room->slug,
                        $room->name,
                        $state['phase'],
                    );
                }

                // PRD 7.3 "on demand: CreditFocusForPhase" — for always-on
                // rooms the flip *is* the phase end. Skip when the "ended"
                // window is really the still-running first one (initial sync
                // computes a synthetic predecessor equal to the current
                // window) — credited_at must never close a live phase.
                if ($ended['phase_ends_at_ms'] <= $nowMs) {
                    $record = $clock->ensurePhaseRecord($room, RoomClockService::windowFromState($ended));
                    CreditFocusForPhase::dispatch((int) $record->id);
                }
            });

        $this->info("Broadcast {$changed} always-on phase change(s).");

        return self::SUCCESS;
    }
}

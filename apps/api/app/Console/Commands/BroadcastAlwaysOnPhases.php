<?php

namespace App\Console\Commands;

use App\Events\RoomPhaseChanged;
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

                $room->forceFill([
                    'phase' => $state['phase'],
                    'phase_started_at' => date('Y-m-d H:i:s', intdiv($state['phase_started_at_ms'], 1000)),
                    'phase_ends_at' => date('Y-m-d H:i:s', intdiv($state['phase_ends_at_ms'], 1000)),
                    'cycle_index' => $state['cycle_index'],
                ])->saveQuietly();

                event(new RoomPhaseChanged($room->id, $room->slug, $state, $nowMs));
                $changed++;
            });

        $this->info("Broadcast {$changed} always-on phase change(s).");

        return self::SUCCESS;
    }
}

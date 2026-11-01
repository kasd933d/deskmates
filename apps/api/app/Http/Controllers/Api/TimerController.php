<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AdvancePhase;
use App\Models\Room;
use App\Services\RoomClockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimerController extends Controller
{
    public function __construct(private readonly RoomClockService $clock) {}

    /**
     * POST /rooms/{id}/timer/{action} — start | pause | skip | reset (host).
     */
    public function __invoke(Request $request, Room $room, string $action): JsonResponse
    {
        $this->authorize('controlTimer', $room);

        if (! in_array($action, ['start', 'pause', 'skip', 'reset'], true)) {
            return response()->json(['message' => 'Unknown timer action.'], 422);
        }

        $state = $this->clock->transition($room, $action);

        // Queue the delayed advance for the new phase's end so transitions
        // happen server-side even with no requests coming in.
        $this->scheduleAdvance($room->fresh(), $state);

        return response()->json([
            'timer' => $state,
            'server_now_ms' => (int) now()->getTimestampMs(),
        ]);
    }

    /**
     * Queue AdvancePhase for the current phase end, idempotent by version.
     */
    public function scheduleAdvance(?Room $room, array $state): void
    {
        if (! $room || $room->isAlwaysOn()) {
            return;
        }

        if ($state['phase'] === RoomClockService::PHASE_IDLE || $state['phase_ends_at_ms'] === null) {
            return;
        }

        $delaySeconds = max(0, intdiv($state['phase_ends_at_ms'] - (int) now()->getTimestampMs(), 1000) + 1);

        AdvancePhase::dispatch($room->id, $room->phase_version)
            ->delay(now()->addSeconds($delaySeconds));
    }
}

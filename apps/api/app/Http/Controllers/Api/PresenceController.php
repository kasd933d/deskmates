<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Services\PresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PRD 4.4/4.5 endpoints: presence heartbeats and intentions.
 */
class PresenceController extends Controller
{
    /**
     * POST /rooms/{room}/heartbeat — PRD 4.4: the client calls this every
     * 20 seconds with its `visible` flag; 60 s of silence makes the member
     * reap-able. Heartbeats are exempt from the general API rate limit and
     * throttled to one per ~15 s per user instead (PRD 6, rate limits).
     */
    public function heartbeat(Request $request, Room $room, PresenceService $presence): JsonResponse
    {
        $data = $request->validate([
            'visible' => ['required', 'boolean'],
        ]);

        $result = $presence->heartbeat($room, $request->user(), (bool) $data['visible']);

        return response()->json([
            'status' => $result['status'],
            'rejoined' => $result['rejoined'],
            'server_now_ms' => (int) now()->getTimestampMs(),
        ]);
    }

    /**
     * PATCH /rooms/{room}/intention — PRD 4.5: max 80 chars, visible to the
     * whole room through the IntentionUpdated broadcast.
     */
    public function updateIntention(Request $request, Room $room, PresenceService $presence): JsonResponse
    {
        $data = $request->validate([
            'intention' => ['nullable', 'string', 'max:80'],
        ]);

        $intention = $presence->setIntention($room, $request->user(), $data['intention'] ?? null);

        return response()->json([
            'intention' => $intention,
            'user_id' => $request->user()->id,
            'server_now_ms' => (int) now()->getTimestampMs(),
        ]);
    }
}

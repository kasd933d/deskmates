<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * PRD 4.10/9: register or remove a browser's Web Push subscription. The
 * endpoint is the identity — a re-subscription with renewed keys (or from
 * a different account on the same browser) updates the row in place, and
 * unsubscribing is scoped to the caller's own row.
 */
class PushController extends Controller
{
    /**
     * POST /push/subscribe.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:750'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $subscription = PushSubscription::query()->updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id' => $request->user()->id,
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'user_agent' => Str::limit($request->userAgent() ?? '', 255),
            ],
        );

        return response()->json([
            'subscription' => [
                'id' => (int) $subscription->id,
                'endpoint' => $subscription->endpoint,
            ],
            'server_now_ms' => (int) now()->getTimestampMs(),
        ], 201);
    }

    /**
     * DELETE /push/subscribe — idempotent; only ever touches the caller's
     * own rows.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:750'],
        ]);

        PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return response()->json([
            'message' => 'Unsubscribed',
            'server_now_ms' => (int) now()->getTimestampMs(),
        ]);
    }
}

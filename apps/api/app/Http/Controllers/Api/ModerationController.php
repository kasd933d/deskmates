<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\User;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PRD 4.12/4.6: host controls over members — kick, ban and chat mute.
 * Authorized by RoomPolicy::moderate (host or admin; always-on rooms have
 * no host, so only admins moderate there).
 */
class ModerationController extends Controller
{
    public function kick(Request $request, Room $room, User $user, ModerationService $moderation): JsonResponse
    {
        $this->authorize('moderate', $room);

        if (($error = $this->rejectInvalidTarget($request, $room, $user)) !== null) {
            return $error;
        }

        $moderation->remove($room, $user, $request->user(), ban: false);

        return response()->json(['message' => 'Member kicked. They can rejoin.']);
    }

    public function ban(Request $request, Room $room, User $user, ModerationService $moderation): JsonResponse
    {
        $this->authorize('moderate', $room);

        if (($error = $this->rejectInvalidTarget($request, $room, $user)) !== null) {
            return $error;
        }

        $result = $moderation->remove($room, $user, $request->user(), ban: true);

        return response()->json([
            'message' => $result['was_member']
                ? 'Member banned. They cannot rejoin.'
                : 'User banned from this room. They cannot join.',
        ]);
    }

    /**
     * PRD 4.6: mute is a chat state on the seat, toggled explicitly by the
     * host (`{muted: true|false}`) so the UI never races a bare toggle.
     */
    public function mute(Request $request, Room $room, User $user, ModerationService $moderation): JsonResponse
    {
        $this->authorize('moderate', $room);

        $request->validate(['muted' => ['required', 'boolean']]);

        if ((int) $user->id === (int) $request->user()->id) {
            return response()->json(['message' => 'You cannot mute yourself.'], 422);
        }

        if (! $room->members()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'That user is not in this room.'], 404);
        }

        $muted = $request->boolean('muted');
        $moderation->setMuted($room, $user, $muted);

        return response()->json(['message' => $muted ? 'Member muted.' : 'Member unmuted.']);
    }

    /**
     * Kick/ban targets must be present (a ban of a user who already left is
     * still fine — handled in the service), never the actor themselves, and
     * never the host unless an admin is doing the cleaning.
     */
    private function rejectInvalidTarget(Request $request, Room $room, User $user): ?JsonResponse
    {
        $actor = $request->user();

        if ((int) $user->id === (int) $actor->id) {
            return response()->json(['message' => 'You cannot kick or ban yourself.'], 422);
        }

        if ($room->isHost($user) && ! $actor->isAdmin()) {
            return response()->json(['message' => 'Only an admin can remove the host.'], 403);
        }

        if (! $room->members()->where('user_id', $user->id)->exists()
            && ! $room->isBanned((int) $user->id)) {
            return response()->json(['message' => 'That user is not in this room.'], 404);
        }

        return null;
    }
}

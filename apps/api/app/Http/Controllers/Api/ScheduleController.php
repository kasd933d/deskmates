<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\ScheduledSession;
use App\Models\ScheduledSessionRsvp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PRD 4.11 scheduled sessions and the §9 `/sessions` routes: list the
 * upcoming bookings the viewer can reach (public rooms plus rooms they
 * belong to or host), create one as the room host, cancel it, and RSVP.
 * All times cross the wire as UTC ISO-8601; the /schedule page renders
 * them in the user's timezone (PRD 4.11).
 */
class ScheduleController extends Controller
{
    /**
     * GET /sessions — upcoming sessions the viewer can see.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $memberRoomIds = RoomMember::query()->where('user_id', $user->id)->pluck('room_id');
        $hostedRoomIds = Room::query()->where('host_id', $user->id)->pluck('id');

        $sessions = ScheduledSession::query()
            ->where('status', ScheduledSession::STATUS_SCHEDULED)
            ->where('starts_at', '>', now())
            ->whereHas('room', function ($query) use ($memberRoomIds, $hostedRoomIds) {
                $query->whereNull('archived_at')
                    ->where(function ($inner) use ($memberRoomIds, $hostedRoomIds) {
                        $inner->where('type', Room::TYPE_PUBLIC)
                            ->orWhereIn('id', $memberRoomIds)
                            ->orWhereIn('id', $hostedRoomIds);
                    });
            })
            ->with(['room', 'host'])
            ->withCount('rsvps')
            ->with(['rsvps' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderBy('starts_at')
            ->limit(100)
            ->get();

        return response()->json([
            'sessions' => $sessions
                ->map(fn (ScheduledSession $session) => $this->payload($session, (int) $user->id))
                ->values()
                ->all(),
            'server_now_ms' => (int) now()->getTimestampMs(),
        ]);
    }

    /**
     * POST /sessions — the room host books a session (PRD 4.11).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'min:2', 'max:80'],
            'starts_at' => ['required', 'date', 'after:now'],
            'focus_minutes' => ['sometimes', 'integer', 'min:10', 'max:90'],
            'short_break_minutes' => ['sometimes', 'integer', 'min:3', 'max:30'],
            'long_break_minutes' => ['sometimes', 'integer', 'min:10', 'max:45'],
            'long_break_every' => ['sometimes', 'integer', 'min:2', 'max:6'],
        ]);

        $room = Room::query()->findOrFail($data['room_id']);

        // Hosts only — RoomPolicy::update also rejects always-on rooms,
        // whose deterministic clock nobody books.
        $this->authorize('update', $room);

        if ($room->archived_at !== null) {
            abort(422, 'This room is closed.');
        }

        $session = ScheduledSession::create([
            'room_id' => $room->id,
            'host_id' => $request->user()->id,
            'title' => $data['title'],
            'starts_at' => $data['starts_at'],
            // PRD 8: the four timer keys, defaulting to the room's current
            // config (the create form pre-fills them from the room).
            'config' => [
                'focus_minutes' => $data['focus_minutes'] ?? $room->focus_minutes,
                'short_break_minutes' => $data['short_break_minutes'] ?? $room->short_break_minutes,
                'long_break_minutes' => $data['long_break_minutes'] ?? $room->long_break_minutes,
                'long_break_every' => $data['long_break_every'] ?? $room->long_break_every,
            ],
        ]);

        return response()->json([
            'session' => $this->payload($session->load(['room', 'host']), (int) $request->user()->id),
            'server_now_ms' => (int) now()->getTimestampMs(),
        ], 201);
    }

    /**
     * DELETE /sessions/{id} — the booking host (or the room's current
     * host, or an admin) cancels a session that has not started yet.
     */
    public function destroy(Request $request, ScheduledSession $session): JsonResponse
    {
        $user = $request->user();

        $canCancel = $session->host_id === $user->id
            || $session->room?->host_id === $user->id
            || $user->isAdmin();

        if (! $canCancel) {
            abort(403, 'Only the session host can cancel this session.');
        }

        if ($session->status !== ScheduledSession::STATUS_SCHEDULED) {
            abort(422, 'This session has already started or was cancelled.');
        }

        $session->update(['status' => ScheduledSession::STATUS_CANCELLED]);

        return $this->respond($session, (int) $user->id);
    }

    /**
     * POST /sessions/{id}/rsvp — idempotent: a second RSVP just reports
     * the current state (the unique pair makes a duplicate impossible).
     */
    public function rsvp(Request $request, ScheduledSession $session): JsonResponse
    {
        $this->authorizeSight($session);

        if ($session->status !== ScheduledSession::STATUS_SCHEDULED) {
            abort(422, 'This session is not open for RSVPs.');
        }

        if ($session->starts_at->lte(now())) {
            abort(422, 'This session has already started.');
        }

        ScheduledSessionRsvp::firstOrCreate([
            'scheduled_session_id' => $session->id,
            'user_id' => $request->user()->id,
        ]);

        return $this->respond($session, (int) $request->user()->id);
    }

    /**
     * DELETE /sessions/{id}/rsvp — cancel an RSVP (idempotent).
     */
    public function cancelRsvp(Request $request, ScheduledSession $session): JsonResponse
    {
        $this->authorizeSight($session);

        ScheduledSessionRsvp::query()
            ->where('scheduled_session_id', $session->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return $this->respond($session, (int) $request->user()->id);
    }

    /**
     * Private rooms stay invisible to outsiders — the same rule as
     * GET /rooms/{slug} (RoomPolicy::view).
     */
    private function authorizeSight(ScheduledSession $session): void
    {
        if ($session->room === null) {
            abort(404, 'Session not found.');
        }

        $this->authorize('view', $session->room);
    }

    private function respond(ScheduledSession $session, int $viewerId): JsonResponse
    {
        return response()->json([
            'session' => $this->payload($session->loadMissing(['room', 'host']), $viewerId),
            'server_now_ms' => (int) now()->getTimestampMs(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ScheduledSession $session, int $viewerId): array
    {
        $room = $session->room;
        $host = $session->host;

        return [
            'id' => (int) $session->id,
            'room_id' => (int) $session->room_id,
            'room' => $room === null ? null : [
                'id' => (int) $room->id,
                'slug' => $room->slug,
                'name' => $room->name,
                'type' => $room->type,
                'theme' => $room->theme,
            ],
            'host' => $host === null ? null : [
                'id' => (int) $host->id,
                'name' => $host->name,
                'avatar_emoji' => $host->avatar_emoji,
                'avatar_color' => $host->avatar_color,
            ],
            'title' => $session->title,
            'starts_at' => $session->starts_at->toISOString(),
            'config' => $session->timerConfig(),
            'status' => $session->status,
            'rsvp_count' => (int) ($session->rsvps_count ?? $session->rsvps()->count()),
            'has_rsvp' => $session->relationLoaded('rsvps')
                ? $session->rsvps->contains('user_id', $viewerId)
                : $session->rsvps()->where('user_id', $viewerId)->exists(),
        ];
    }
}

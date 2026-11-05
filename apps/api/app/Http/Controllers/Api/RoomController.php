<?php

namespace App\Http\Controllers\Api;

use App\Events\RoomSettingsUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Services\PresenceService;
use App\Services\RoomClockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    /**
     * Lobby: always-on and public rooms, plus the current user's rooms.
     */
    public function index(Request $request): JsonResponse
    {
        $rooms = Room::query()
            ->whereNull('archived_at')
            ->whereIn('type', [Room::TYPE_ALWAYS_ON, Room::TYPE_PUBLIC])
            ->withCount('members')
            ->orderByRaw("CASE type WHEN 'always_on' THEN 0 ELSE 1 END")
            ->orderByDesc('members_count')
            ->paginate(min((int) $request->query('per_page', 25), 50));

        $myRoomIds = $request->user()?->membership()->pluck('room_id') ?? collect();

        $myRooms = Room::query()
            ->withCount('members')
            ->with('host')
            ->whereIn('id', $myRoomIds)
            ->get();

        return response()->json([
            'rooms' => RoomResource::collection($rooms->getCollection())->toArray($request),
            'my_rooms' => RoomResource::collection($myRooms)->toArray($request),
            'links' => $rooms->only('first_page_url', 'last_page_url', 'next_page_url', 'prev_page_url'),
            'meta' => [
                'current_page' => $rooms->currentPage(),
                'per_page' => $rooms->perPage(),
                'total' => $rooms->total(),
            ],
            'server_now_ms' => (int) now()->getTimestampMs(),
        ]);
    }

    public function store(StoreRoomRequest $request, PresenceService $presence): JsonResponse
    {
        $room = DB::transaction(function () use ($request, $presence) {
            $data = $request->validated();

            $room = Room::create([
                ...$data,
                'max_members' => $data['max_members'] ?? Room::MAX_MEMBERS[$data['type']],
                // PRD 4.6: silent focus defaults ON for always-on rooms (the
                // seeder sets it) and off for host-created rooms — there the
                // host enables it.
                'silent_focus' => $data['silent_focus'] ?? false,
                'host_id' => $request->user()->id,
                'last_active_at' => now(),
            ]);

            // Creating a room auto-joins it, so the one-room rule applies
            // here too: any previous membership is moved, not duplicated.
            $presence->moveToRoom($request->user(), $room);

            return $room;
        });

        return response()->json([
            'room' => (new RoomResource($room->load('host')))->toArray($request),
        ], 201);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $room = Room::where('slug', $slug)
            ->whereNull('archived_at')
            ->with(['host', 'members.user'])
            ->firstOrFail();

        if ($room->isPrivate() && ! $room->isHost($request->user()) && ! $room->members->contains('user_id', $request->user()?->id)) {
            return response()->json(['message' => 'This room is private. An invite code is required.'], 403);
        }

        $clock = app(RoomClockService::class);
        $nowMs = (int) now()->getTimestampMs();

        // Lazy fallback: advance a user room whose phase expired while no
        // job ran (idempotent, cheap check on every state fetch).
        $clock->advanceIfExpired($room, $nowMs);

        $fresh = $room->fresh(['members.user']);

        $timer = $room->isAlwaysOn()
            ? $clock->alwaysOnPhase($room, $nowMs)
            : $clock->userRoomState($fresh);

        return response()->json([
            'room' => (new RoomResource($room))->toArray($request),
            'timer' => $timer,
            // Milestone 3: member cards (avatar, name, intention, status dot)
            // seed the grid; the presence channel keeps it live.
            'members' => app(PresenceService::class)->memberCards($fresh, $timer['phase']),
            'server_now_ms' => $nowMs,
        ]);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        $this->authorize('update', $room);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:60'],
            'theme' => ['sometimes', 'string', 'max:32'],
            'focus_minutes' => ['sometimes', 'integer', 'min:10', 'max:90'],
            'short_break_minutes' => ['sometimes', 'integer', 'min:3', 'max:30'],
            'long_break_minutes' => ['sometimes', 'integer', 'min:10', 'max:45'],
            'long_break_every' => ['sometimes', 'integer', 'min:2', 'max:6'],
            'max_members' => ['sometimes', 'integer', 'min:2', 'max:'.Room::MAX_MEMBERS[$room->type]],
            'silent_focus' => ['sometimes', 'boolean'],
        ]);

        $room->update($data);

        // M4: settings edits broadcast so every client (and the host's own
        // toggle UI) applies them without a refetch — same event host
        // transfer already uses.
        event(RoomSettingsUpdated::fromRoom($room->fresh()));

        return response()->json([
            'room' => (new RoomResource($room->fresh()->load('host')))->toArray($request),
        ]);
    }

    public function destroy(Request $request, Room $room): JsonResponse
    {
        $this->authorize('close', $room);

        $room->update(['archived_at' => now()]);

        return response()->json(['message' => 'Room closed']);
    }

    public function join(Request $request, Room $room, PresenceService $presence): JsonResponse
    {
        $this->authorize('join', $room);

        // PRD 4.12: a ban outlives membership — checked here (the rejoin
        // path), in heartbeat self-heal and in channel auth.
        if ($room->isBanned((int) $request->user()->id)) {
            return response()->json(['message' => 'You are banned from this room.'], 403);
        }

        if ($room->isPrivate()) {
            $request->validate(['invite_code' => ['required', 'string', Rule::in([$room->invite_code])]]);
        }

        // One-room rule (PRD M3): joining elsewhere moves this user's single
        // membership, broadcasts MemberRemoved to the old room and transfers
        // its hostship if they were the host.
        $presence->moveToRoom($request->user(), $room);

        return response()->json([
            'room' => (new RoomResource($room->fresh()->load('host', 'members')))->toArray($request),
        ]);
    }

    public function leave(Request $request, Room $room, PresenceService $presence): JsonResponse
    {
        $member = $room->members()
            ->where('user_id', $request->user()->id)
            ->first();

        if ($member !== null) {
            $presence->removeMember($member);
        }

        return response()->json(['message' => 'Left room']);
    }
}

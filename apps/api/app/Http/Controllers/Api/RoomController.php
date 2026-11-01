<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Models\RoomMember;
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

    public function store(StoreRoomRequest $request): JsonResponse
    {
        $room = DB::transaction(function () use ($request) {
            $data = $request->validated();

            $room = Room::create([
                ...$data,
                'max_members' => $data['max_members'] ?? Room::MAX_MEMBERS[$data['type']],
                'host_id' => $request->user()->id,
                'last_active_at' => now(),
            ]);

            RoomMember::create([
                'room_id' => $room->id,
                'user_id' => $request->user()->id,
                'joined_at' => now(),
                'last_seen_at' => now(),
            ]);

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

        return response()->json([
            'room' => (new RoomResource($room))->toArray($request),
            'server_now_ms' => (int) now()->getTimestampMs(),
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

    public function join(Request $request, Room $room): JsonResponse
    {
        $this->authorize('join', $room);

        if ($room->isPrivate()) {
            $request->validate(['invite_code' => ['required', 'string', Rule::in([$room->invite_code])]]);
        }

        $user = $request->user();

        RoomMember::updateOrCreate(
            ['user_id' => $user->id],
            [
                'room_id' => $room->id,
                'joined_at' => now(),
                'last_seen_at' => now(),
            ],
        );

        return response()->json([
            'room' => (new RoomResource($room->fresh()->load('host', 'members')))->toArray($request),
        ]);
    }

    public function leave(Request $request, Room $room): JsonResponse
    {
        RoomMember::where('user_id', $request->user()->id)
            ->where('room_id', $room->id)
            ->delete();

        return response()->json(['message' => 'Left room']);
    }
}

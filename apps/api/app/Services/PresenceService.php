<?php

namespace App\Services;

use App\Events\IntentionUpdated;
use App\Events\MemberRemoved;
use App\Events\MemberStatusChanged;
use App\Events\RoomSettingsUpdated;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * PRD 4.4 presence: heartbeats are the authoritative record of who is in a
 * room. The Echo presence channel only drives the instant UI; crediting and
 * membership are decided here.
 */
class PresenceService
{
    public function __construct(
        private RoomClockService $clock,
        private FocusCreditService $focus,
    ) {}

    /**
     * PRD 4.4: the client heartbeats every 20 s with its visibility; a member
     * is gone after 60 s without one (the reaper enforces it). The endpoint
     * doubles as self-healing: a member reaped while their tab slept rejoins
     * open rooms automatically.
     *
     * @return array{status: string, rejoined: bool, status_changed: bool}
     */
    public function heartbeat(Room $room, User $user, bool $visible): array
    {
        if ($room->archived_at !== null) {
            throw new HttpResponseException(response()->json([
                'message' => 'This room has been closed.',
            ], 404));
        }

        // A stale phase would misreport focus vs break right after expiry —
        // same lazy fallback the room state endpoint uses. Refresh after a
        // transition so the attendance accrual below sees the *new* phase: a
        // beat landing after the boundary must not extend the ended window
        // (PRD 4.8 accrues only "during a focus phase").
        if ($this->clock->advanceIfExpired($room)) {
            $room->refresh();
        }

        $rejoined = false;

        $result = DB::transaction(function () use ($room, $user, $visible, &$rejoined) {
            $member = RoomMember::query()
                ->where('room_id', $room->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($member === null) {
                $member = $this->restoreMembership($room, $user);
                $rejoined = $member !== null;

                if ($member === null) {
                    return null; // restoreMembership threw already
                }
            }

            // PRD 4.8 accrual: elapsed time since the previous beat. Captured
            // before the update below overwrites last_seen_at; a re-joined
            // member passes null (their fresh row starts the clock at "now").
            $previousSeenAt = $rejoined ? null : $member->last_seen_at;

            // The stored status is what the room was last told; the away flip
            // fires only when that reported state changes. Deriving "was" from
            // hidden_since would already read away on the second hidden
            // heartbeat, so clients would never be told the member left.
            $wasAway = $member->status === RoomMember::STATUS_AWAY;

            $hiddenSince = $visible ? null : ($member->hidden_since ?? now());

            $phase = $this->resolvePhase($room);

            $status = RoomMember::displayStatus(
                tap($member, fn (RoomMember $m) => $m->hidden_since = $hiddenSince),
                $phase,
            );

            $nowAway = $status === RoomMember::STATUS_AWAY;

            $member->update([
                'last_seen_at' => now(),
                'hidden_since' => $hiddenSince,
                'status' => $status,
            ]);

            // Keeps StopIdleTimers honest: activity is proven by heartbeats,
            // not just by join/leave rows.
            $room->update(['last_active_at' => now()]);

            // PRD 4.8: heartbeats during a focus phase accrue attendance
            // (capped at 45 s per beat); breaks accrue nothing.
            $this->focus->recordAttendance($room, $user, $phase, $previousSeenAt);

            return [
                'status' => $status,
                'status_changed' => $wasAway !== $nowAway,
            ];
        });

        if ($result === null) {
            return ['status' => RoomMember::STATUS_BREAK, 'rejoined' => false, 'status_changed' => false];
        }

        if ($result['status_changed']) {
            event(new MemberStatusChanged($room->id, $user->id, $result['status']));
        }

        return [...$result, 'rejoined' => $rejoined];
    }

    /**
     * PRD 4.5: one-line intention (max 80 chars) visible to the room.
     * Allowed any time — the PRD describes intentions as being written
     * "before or during a focus phase", which is when they get read, not a
     * phase-gated write (decision logged in docs/decisions.md).
     */
    public function setIntention(Room $room, User $user, ?string $intention): ?string
    {
        $member = RoomMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->first();

        if ($member === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'Join the room before setting an intention.',
            ], 403));
        }

        $intention = $intention !== null ? trim($intention) : null;
        $intention = $intention === '' ? null : $intention;

        $member->update(['intention' => $intention]);

        event(new IntentionUpdated($room->id, $user->id, $intention));

        return $intention;
    }

    /**
     * PRD 4.4/7.3: called by the `presence:reap-stale` command (every 30 s)
     * for members with no heartbeat in 60 s, and by the leave endpoint.
     * Transfers hostship before broadcasting (PRD M3: host transfer).
     */
    public function removeMember(RoomMember $member): void
    {
        $room = $member->room;
        $userId = (int) $member->user_id;
        $wasHost = (int) $room->host_id === $userId;

        $hostChanged = DB::transaction(function () use ($member, $room, $wasHost) {
            $member->delete();

            if (! $wasHost) {
                return false;
            }

            return $this->transferHost($room);
        });

        event(new MemberRemoved($room->id, $userId));

        if ($hostChanged) {
            event(RoomSettingsUpdated::fromRoom($room->fresh()));
        }
    }

    /**
     * Move the user's single membership (one-room rule, PRD M3) to $room.
     * Leaving a room where they were host transfers hostship to the member
     * who has been around the longest; the old room gets a MemberRemoved so
     * its clients update live instead of waiting for the reaper.
     *
     * @return array{member: RoomMember, old_room_id: int|null}
     */
    public function moveToRoom(User $user, Room $room): array
    {
        $oldRoomId = null;
        $hostChanged = false;

        $member = DB::transaction(function () use ($user, $room, &$oldRoomId, &$hostChanged) {
            /** @var RoomMember|null $member */
            $member = RoomMember::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($member !== null && $member->room_id === $room->id) {
                $member->update(['last_seen_at' => now(), 'hidden_since' => null]);

                return $member;
            }

            $freshAttributes = [
                'room_id' => $room->id,
                'user_id' => $user->id,
                'joined_at' => now(),
                'last_seen_at' => now(),
                // Intentions belong to the room they were written for.
                'intention' => null,
                'hidden_since' => null,
                'status' => RoomMember::STATUS_BREAK,
            ];

            if ($member === null) {
                $member = RoomMember::create($freshAttributes);
            } else {
                $oldRoomId = (int) $member->room_id;
                $oldRoom = Room::find($oldRoomId);
                $member->update($freshAttributes);

                if ($oldRoom !== null && (int) $oldRoom->host_id === $user->id) {
                    $hostChanged = $this->transferHost($oldRoom);
                }
            }

            return $member;
        });

        if ($oldRoomId !== null) {
            event(new MemberRemoved($oldRoomId, (int) $user->id));
        }

        if ($hostChanged && $oldRoomId !== null) {
            event(RoomSettingsUpdated::fromRoom(Room::findOrFail($oldRoomId)));
        }

        return ['member' => $member, 'old_room_id' => $oldRoomId];
    }

    /**
     * PRD 4.4: member cards (avatar, name, intention, status dot) — the seed
     * for the member grid; live updates arrive over the presence channel.
     * Status is computed fresh from (hidden_since, phase) so a card is never
     * stale the moment it renders.
     *
     * @return array<int, array<string, mixed>>
     */
    public function memberCards(Room $room, ?string $phase = null): array
    {
        $phase ??= $this->resolvePhase($room);
        $hostId = (int) $room->host_id;

        return $room->members
            ->sortBy([['joined_at', 'asc'], ['id', 'asc']])
            ->values()
            ->map(fn ($member) => [
                'user_id' => (int) $member->user_id,
                'name' => $member->user?->name,
                'avatar_emoji' => $member->user?->avatar_emoji,
                'avatar_color' => $member->user?->avatar_color,
                'intention' => $member->intention,
                'status' => RoomMember::displayStatus($member, $phase),
                'is_host' => (int) $member->user_id === $hostId,
                'is_muted' => (bool) $member->is_muted,
                'joined_at' => $member->joined_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * Current phase of any room type: stored for user rooms, deterministic
     * for always-on rooms.
     */
    public function resolvePhase(Room $room): string
    {
        if ($room->isAlwaysOn()) {
            return $this->clock->alwaysOnPhase($room, (int) now()->getTimestampMs())['phase'];
        }

        return $room->phase;
    }

    /**
     * Reap enforcement lives in the command; this is the single membership
     * lookup shared by heartbeat self-healing paths.
     *
     * @return RoomMember|null null when the room is not open for joining
     */
    private function restoreMembership(Room $room, User $user): ?RoomMember
    {
        // PRD 4.12: a ban outlives the membership row — the self-heal path
        // must never resurrect a banned seat.
        if ($room->isBanned((int) $user->id)) {
            throw new HttpResponseException(response()->json([
                'message' => 'You are banned from this room.',
            ], 403));
        }

        // Membership exists in ANOTHER room: another tab joined elsewhere
        // (one-room rule). Rejoining here would start a two-tab tug of war.
        $elsewhere = RoomMember::query()->where('user_id', $user->id)->first();

        if ($elsewhere !== null) {
            $elsewhereRoom = $elsewhere->room;

            throw new HttpResponseException(response()->json([
                'message' => 'You are in a different room right now.',
                'current_room_slug' => $elsewhereRoom?->slug,
                'current_room_id' => (int) $elsewhere->room_id,
            ], 409));
        }

        // Rejoin open rooms (the sleep/reap recovery path); private rooms
        // need a fresh invite (never trust the client for membership).
        if ($room->isPrivate()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Your seat expired. Ask the host for a new invite code.',
            ], 403));
        }

        if ($room->members()->count() >= $room->max_members) {
            throw new HttpResponseException(response()->json([
                'message' => 'This room is full.',
            ], 409));
        }

        return RoomMember::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'joined_at' => now(),
            'last_seen_at' => now(),
            'status' => RoomMember::STATUS_BREAK,
        ]);
    }

    /**
     * Give the room to the longest-present remaining member. An empty room
     * keeps its host so they reclaim controls on return (the idle/archive
     * commands handle abandoned rooms).
     */
    private function transferHost(Room $room): bool
    {
        $next = $room->members()
            ->orderBy('joined_at')
            ->orderBy('id')
            ->first();

        if ($next === null) {
            return false;
        }

        $room->update(['host_id' => $next->user_id]);

        return true;
    }
}

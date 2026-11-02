<?php

use App\Models\Room;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
|----------------------------------------------------------------------
| Presence channel (Milestone 3)
|----------------------------------------------------------------------
| The client subscribes to `presence-room.{id}`; Laravel strips the
| `presence-` prefix before matching, so the pattern is `room.{id}`
| (UsePusherChannelConventions::normalizeChannelName).
|
| PRD 7.2: channel auth uses policies — private rooms require membership,
| and a non-member may not enter a full room (they could not join it, so
| they must not watch its presence either). Members, the host and admins
| always pass. Banned-user rejection arrives with moderation in M4 (there
| is no ban column yet).
|
| The returned array becomes the presence member's `user_info` — it is what
| `.here()` / `.joining()` / `.leaving()` deliver on the client.
|
| @return array<string, mixed>|false
*/
Broadcast::channel('room.{roomId}', function ($user, $roomId) {
    $room = Room::find($roomId);

    if ($room === null || $room->archived_at !== null) {
        return false;
    }

    $isMember = $room->members()->where('user_id', $user->id)->exists();
    $privileged = $isMember || $room->isHost($user) || $user->isAdmin();

    if ($room->isPrivate() && ! $privileged) {
        return false;
    }

    if (! $privileged && $room->members()->count() >= $room->max_members) {
        return false;
    }

    return [
        'id' => (int) $user->id,
        'name' => $user->name,
        'avatar_emoji' => $user->avatar_emoji,
        'avatar_color' => $user->avatar_color,
    ];
});

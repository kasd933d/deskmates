<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

class RoomPolicy
{
    public function view(User $user, Room $room): bool
    {
        if ($user->isAdmin() || ! $room->isPrivate()) {
            return true;
        }

        return $room->isHost($user) || $room->members()->where('user_id', $user->id)->exists();
    }

    public function update(User $user, Room $room): bool
    {
        // Always-on rooms run on a deterministic clock; nobody controls them.
        if ($room->isAlwaysOn()) {
            return false;
        }

        return $user->isAdmin() || $room->isHost($user);
    }

    public function close(User $user, Room $room): bool
    {
        if ($room->isAlwaysOn()) {
            return false;
        }

        return $user->isAdmin() || $room->isHost($user);
    }

    public function join(User $user, Room $room): bool
    {
        // Private rooms are gated by the invite code, validated in the
        // controller after this policy check passes.
        return $room->archived_at === null;
    }
}

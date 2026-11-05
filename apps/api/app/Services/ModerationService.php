<?php

namespace App\Services;

use App\Events\MemberKicked;
use App\Events\MemberMuted;
use App\Models\Room;
use App\Models\RoomBan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PRD 4.12: host moderation — kick (membership removed, may rejoin) and ban
 * (membership removed + permanent room_bans row). Both ride
 * PresenceService::removeMember for the membership side effects (host
 * transfer, MemberRemoved broadcast) and then tell the target personally
 * over their private channel.
 */
class ModerationService
{
    public function __construct(private PresenceService $presence) {}

    /**
     * @return array{banned: bool, was_member: bool}
     */
    public function remove(Room $room, User $target, User $actor, bool $ban): array
    {
        $wasMember = false;

        DB::transaction(function () use ($room, $target, $actor, $ban, &$wasMember) {
            if ($ban) {
                RoomBan::firstOrCreate(
                    ['room_id' => $room->id, 'user_id' => $target->id],
                    ['banned_by' => $actor->id],
                );
            }

            $member = $room->members()->where('user_id', $target->id)->first();

            if ($member !== null) {
                $wasMember = true;
                // Host transfer + MemberRemoved broadcast happen inside.
                $this->presence->removeMember($member);
            }
        });

        // The personal verdict: presence alone cannot say "you were kicked"
        // (vs. left/reaped), and a ban must be explicit before any rejoin.
        event(new MemberKicked(
            (int) $target->id,
            (int) $room->id,
            $room->slug,
            $ban ? 'banned' : 'kicked',
        ));

        return ['banned' => $ban, 'was_member' => $wasMember];
    }

    /**
     * PRD 4.6: host mutes/unmutes a member's chat (is_muted on the seat).
     */
    public function setMuted(Room $room, User $target, bool $muted): void
    {
        $room->members()
            ->where('user_id', $target->id)
            ->firstOrFail()
            ->update(['is_muted' => $muted]);

        event(new MemberMuted((int) $room->id, (int) $target->id, $muted));
    }
}

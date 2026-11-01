<?php

namespace Database\Seeders;

use App\Models\Room;
use Illuminate\Database\Seeder;

class AlwaysOnRoomSeeder extends Seeder
{
    /**
     * Official always-on rooms. Their timers are deterministic: the phase is a
     * pure function of (now, anchor_epoch, config) — no DB state or jobs.
     *
     * Anchors are aligned to a UTC midnight so schedules are easy to reason
     * about and identical for every install that seeds at the same commit.
     */
    public function run(): void
    {
        $anchor = 1735689600; // 2025-01-01T00:00:00Z

        $rooms = [
            [
                'name' => 'The Library',
                'slug' => 'the-library',
                'focus_minutes' => 25,
                'short_break_minutes' => 5,
                'long_break_minutes' => 15,
                'long_break_every' => 4,
                'theme' => 'library',
            ],
            [
                'name' => 'Deep Work Cafe',
                'slug' => 'deep-work-cafe',
                'focus_minutes' => 50,
                'short_break_minutes' => 10,
                'long_break_minutes' => 20,
                'long_break_every' => 3,
                'theme' => 'cafe',
            ],
            [
                'name' => 'Night Owls',
                'slug' => 'night-owls',
                'focus_minutes' => 45,
                'short_break_minutes' => 10,
                'long_break_minutes' => 15,
                'long_break_every' => 4,
                'theme' => 'night',
            ],
            [
                'name' => 'Sprint Room',
                'slug' => 'sprint-room',
                'focus_minutes' => 15,
                'short_break_minutes' => 5,
                'long_break_minutes' => 10,
                'long_break_every' => 4,
                'theme' => 'sprint',
            ],
        ];

        foreach ($rooms as $room) {
            Room::updateOrCreate(
                ['slug' => $room['slug']],
                [
                    ...$room,
                    'type' => Room::TYPE_ALWAYS_ON,
                    'host_id' => null,
                    'invite_code' => null,
                    'max_members' => Room::MAX_MEMBERS[Room::TYPE_ALWAYS_ON],
                    'silent_focus' => true,
                    'phase' => 'idle',
                    'anchor_epoch' => $anchor,
                    'last_active_at' => now(),
                ],
            );
        }
    }
}

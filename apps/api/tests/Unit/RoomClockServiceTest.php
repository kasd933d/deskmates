<?php

namespace Tests\Unit;

use App\Models\Room;
use App\Services\RoomClockService;
use PHPUnit\Framework\TestCase;

class RoomClockServiceTest extends TestCase
{
    private RoomClockService $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new RoomClockService;
    }

    private function alwaysOnRoom(int $focus = 25, int $short = 5, int $long = 15, int $every = 4): Room
    {
        return new Room([
            'type' => Room::TYPE_ALWAYS_ON,
            'anchor_epoch' => 1735689600,
            'focus_minutes' => $focus,
            'short_break_minutes' => $short,
            'long_break_minutes' => $long,
            'long_break_every' => $every,
        ]);
    }

    public function test_first_cycle_is_focus_then_short_break(): void
    {
        $room = $this->alwaysOnRoom();
        $anchor = 1735689600; // 2025-01-01T00:00:00Z

        // t = anchor: focus started at anchor, ends at +25 min.
        $phase = $this->clock->alwaysOnPhase($room, $anchor * 1000);
        $this->assertSame('focus', $phase['phase']);
        $this->assertSame(($anchor + 25 * 60) * 1000, $phase['phase_ends_at_ms']);
        $this->assertSame(0, $phase['cycle_index']);

        // t = anchor + 26 min: inside the first short break (25–30 min).
        $phase = $this->clock->alwaysOnPhase($room, ($anchor + 26 * 60) * 1000);
        $this->assertSame('short_break', $phase['phase']);
        $this->assertSame(($anchor + 30 * 60) * 1000, $phase['phase_ends_at_ms']);
        $this->assertSame(0, $phase['cycle_index']);
    }

    public function test_long_break_every_nth_cycle(): void
    {
        $room = $this->alwaysOnRoom(focus: 25, short: 5, long: 15, every: 4);
        $anchor = 1735689600;

        // One cycle = 30 min (focus + short). After 3 full cycles (90 min),
        // the 4th cycle's focus runs 90–115 min, then a LONG break 115–130.
        $phase = $this->clock->alwaysOnPhase($room, ($anchor + 100 * 60) * 1000);
        $this->assertSame('focus', $phase['phase']);
        $this->assertSame(3, $phase['cycle_index']);

        $phase = $this->clock->alwaysOnPhase($room, ($anchor + 120 * 60) * 1000);
        $this->assertSame('long_break', $phase['phase']);
        $this->assertSame(3, $phase['cycle_index']);
        $this->assertSame(($anchor + 130 * 60) * 1000, $phase['phase_ends_at_ms']);
    }

    public function test_period_repeats_after_every_cycles(): void
    {
        $room = $this->alwaysOnRoom(focus: 25, short: 5, long: 15, every: 4);
        $anchor = 1735689600;

        // Period = 3 * 30 + 40 = 130 min. The state at t and t + 130 min
        // must match phase and remaining time exactly.
        $t = $anchor + 26 * 60;
        $first = $this->clock->alwaysOnPhase($room, $t * 1000);
        $second = $this->clock->alwaysOnPhase($room, ($t + 130 * 60) * 1000);

        $this->assertSame($first['phase'], $second['phase']);
        $this->assertSame(
            $first['phase_ends_at_ms'] + 130 * 60 * 1000,
            $second['phase_ends_at_ms'],
        );
        $this->assertSame($first['cycle_index'] + 4, $second['cycle_index']);
    }

    public function test_phase_boundaries_are_exact(): void
    {
        $room = $this->alwaysOnRoom(focus: 25, short: 5, long: 15, every: 4);
        $anchor = 1735689600;

        // 1 ms before the focus phase ends: still focus.
        $phase = $this->clock->alwaysOnPhase($room, ($anchor + 25 * 60) * 1000 - 1);
        $this->assertSame('focus', $phase['phase']);

        // Exactly at the end: the break has begun.
        $phase = $this->clock->alwaysOnPhase($room, ($anchor + 25 * 60) * 1000);
        $this->assertSame('short_break', $phase['phase']);
    }

    public function test_time_before_anchor_clamps_to_first_focus(): void
    {
        $room = $this->alwaysOnRoom();
        $anchor = 1735689600;

        $phase = $this->clock->alwaysOnPhase($room, ($anchor - 3600) * 1000);
        $this->assertSame('focus', $phase['phase']);
        $this->assertSame(0, $phase['cycle_index']);
    }

    public function test_cycle_rotation_with_three_cycle_period(): void
    {
        $room = $this->alwaysOnRoom(focus: 10, short: 3, long: 10, every: 2);
        $anchor = 1735689600;

        // Period = 1 * 13 + 20 = 33 min: cycle 0 focus+short (0–13),
        // cycle 1 focus+long (13–33).
        $phase = $this->clock->alwaysOnPhase($room, ($anchor + 5 * 60) * 1000);
        $this->assertSame('focus', $phase['phase']);
        $this->assertSame(0, $phase['cycle_index']);

        $phase = $this->clock->alwaysOnPhase($room, ($anchor + 14 * 60) * 1000);
        $this->assertSame('focus', $phase['phase']);
        $this->assertSame(1, $phase['cycle_index']);

        $phase = $this->clock->alwaysOnPhase($room, ($anchor + 26 * 60) * 1000);
        $this->assertSame('long_break', $phase['phase']);
        $this->assertSame(1, $phase['cycle_index']);

        // Second period starts at 33 min.
        $phase = $this->clock->alwaysOnPhase($room, ($anchor + 34 * 60) * 1000);
        $this->assertSame('focus', $phase['phase']);
        $this->assertSame(2, $phase['cycle_index']);
    }
}

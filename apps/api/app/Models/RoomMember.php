<?php

namespace App\Models;

use App\Services\RoomClockService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomMember extends Model
{
    use HasFactory;

    public const STATUS_FOCUSING = 'focusing';

    public const STATUS_BREAK = 'break';

    public const STATUS_AWAY = 'away';

    /** PRD 4.4: "Away" appears after the tab has been hidden for this long. */
    public const AWAY_AFTER_MINUTES = 2;

    protected $fillable = [
        'room_id',
        'user_id',
        'intention',
        'status',
        'joined_at',
        'last_seen_at',
        'hidden_since',
        'is_muted',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'hidden_since' => 'datetime',
            'is_muted' => 'boolean',
        ];
    }

    /**
     * Display status for a member card: `away` once the tab has been hidden
     * for 2+ minutes, otherwise `focusing` during a focus phase and `break`
     * during any other phase (idle counts as break — the PRD card states are
     * focusing / on break / away only).
     *
     * Pure function of (hidden_since, phase): computed at read time so cards
     * never show a stale phase, and at heartbeat time so the stored column
     * tracks presence for background jobs.
     */
    public static function displayStatus(?self $member, string $phase): string
    {
        $hiddenSince = $member?->hidden_since;

        if ($hiddenSince !== null && $hiddenSince->lte(now()->subMinutes(self::AWAY_AFTER_MINUTES))) {
            return self::STATUS_AWAY;
        }

        return $phase === RoomClockService::PHASE_FOCUS
            ? self::STATUS_FOCUSING
            : self::STATUS_BREAK;
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD 8 focus_sessions — a credited focus block. One row per (user, phase);
 * `minutes` already respects the 5-minute minimum and the 12-hour daily cap,
 * `local_date` is the phase-end date in the user's timezone, and `outcome`
 * holds the P1 break-start check-in answer (PRD 4.5).
 */
class FocusSession extends Model
{
    protected $fillable = [
        'user_id',
        'room_id',
        'phase_id',
        'minutes',
        'intention',
        'outcome',
        'local_date',
    ];

    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(RoomPhase::class, 'phase_id');
    }
}

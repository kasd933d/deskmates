<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRD 8 room_phases — one row per phase interval of a room. Created on
 * user-room transitions and lazily for always-on windows; `credited_at`
 * records when the credit pipeline finished with the row (PRD 4.8).
 */
class RoomPhase extends Model
{
    protected $fillable = [
        'room_id',
        'type',
        'started_at',
        'ends_at',
        'cycle_index',
        'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'credited_at' => 'datetime',
            'cycle_index' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(PhaseAttendance::class, 'phase_id');
    }
}

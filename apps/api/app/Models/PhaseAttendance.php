<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD 8 phase_attendance — per-member seconds present during a focus phase.
 * Accrued by PresenceService::heartbeat (each beat adds elapsed time since
 * the previous beat, capped at 45 s) and read once when the phase ends.
 */
class PhaseAttendance extends Model
{
    /** PRD 8 names the table `phase_attendance` (Eloquent would pluralize). */
    protected $table = 'phase_attendance';

    protected $fillable = [
        'phase_id',
        'user_id',
        'seconds_present',
    ];

    protected function casts(): array
    {
        return [
            'seconds_present' => 'integer',
        ];
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(RoomPhase::class, 'phase_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

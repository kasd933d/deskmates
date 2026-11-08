<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD 8: one row per (session, user), unique at the database level —
 * RSVP is idempotent and the row is what puts a user on the 10-minute
 * reminder fan-out (PRD 4.10).
 */
class ScheduledSessionRsvp extends Model
{
    protected $fillable = [
        'scheduled_session_id',
        'user_id',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ScheduledSession::class, 'scheduled_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

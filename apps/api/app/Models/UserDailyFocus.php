<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD 8 user_daily_focus — daily rollup of credited focus per user. Written
 * atomically with each focus_session (so "today" is always live) and rebuilt
 * from sessions nightly by RollupDailyFocus. `date` stays an uncast string
 * ('Y-m-d') so comparisons and JSON round-trips are plain text.
 */
class UserDailyFocus extends Model
{
    /** PRD 8 names the table `user_daily_focus` (Eloquent would pluralize). */
    protected $table = 'user_daily_focus';

    protected $fillable = [
        'user_id',
        'date',
        'minutes',
        'sessions',
    ];

    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'sessions' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

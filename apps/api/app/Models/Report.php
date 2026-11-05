<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD 4.12: a user or message report waiting in the admin queue.
 * `status` moves open → reviewed | dismissed from /admin/reports.
 */
class Report extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'reporter_id',
        'target_user_id',
        'message_id',
        'room_id',
        'reason',
        'status',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}

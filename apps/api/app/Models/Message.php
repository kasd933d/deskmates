<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PRD 4.6/8: a chat message. Soft-deleted rows are tombstones — the API
 * filters them and MessageDeleted tells clients to drop the bubble.
 */
class Message extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'room_id',
        'user_id',
        'body',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

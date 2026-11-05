<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD 4.12: a user banned from one room (unique per room/user pair).
 * Ban checks live on Room::isBanned() and gate join, heartbeat self-heal
 * and channel authorization.
 */
class RoomBan extends Model
{
    protected $fillable = [
        'room_id',
        'user_id',
        'banned_by',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by');
    }
}

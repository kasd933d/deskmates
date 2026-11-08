<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD 8/4.10: a browser's Web Push registration. `endpoint` is globally
 * unique — re-subscribing (fresh keys, another account on the same
 * browser) moves the row in place, and a404/410 answer from the push
 * service deletes it on the spot.
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'p256dh',
        'auth',
        'user_agent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

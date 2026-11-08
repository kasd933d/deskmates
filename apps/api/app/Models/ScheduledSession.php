<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRD 4.11/8: a host books a session (title, start time, timer config) for
 * a public or private room. `sessions:start` claims the row with the
 * scheduled → started flip and applies `config` to the room; the nightly
 * sweep and RSVP fan-out hang off `starts_at` / `reminded_at`.
 */
class ScheduledSession extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_STARTED = 'started';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'room_id',
        'host_id',
        'title',
        'starts_at',
        'config',
        'status',
        'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'reminded_at' => 'datetime',
            'config' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ScheduledSession $session) {
            $session->status ??= self::STATUS_SCHEDULED;
        });
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(ScheduledSessionRsvp::class);
    }

    /**
     * The four timer keys from the `config` JSON (PRD 8) with room-safe
     * defaults, so a row written before a key existed still starts a sane
     * timer.
     *
     * @return array{focus_minutes: int, short_break_minutes: int, long_break_minutes: int, long_break_every: int}
     */
    public function timerConfig(): array
    {
        return [
            'focus_minutes' => (int) ($this->config['focus_minutes'] ?? 25),
            'short_break_minutes' => (int) ($this->config['short_break_minutes'] ?? 5),
            'long_break_minutes' => (int) ($this->config['long_break_minutes'] ?? 15),
            'long_break_every' => (int) ($this->config['long_break_every'] ?? 4),
        ];
    }
}

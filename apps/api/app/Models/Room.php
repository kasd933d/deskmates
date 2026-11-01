<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Room extends Model
{
    use HasFactory;

    public const TYPE_ALWAYS_ON = 'always_on';

    public const TYPE_PUBLIC = 'public';

    public const TYPE_PRIVATE = 'private';

    public const MAX_MEMBERS = [
        self::TYPE_ALWAYS_ON => 100,
        self::TYPE_PUBLIC => 25,
        self::TYPE_PRIVATE => 12,
    ];

    protected $fillable = [
        'slug',
        'name',
        'type',
        'host_id',
        'invite_code',
        'theme',
        'max_members',
        'focus_minutes',
        'short_break_minutes',
        'long_break_minutes',
        'long_break_every',
        'silent_focus',
        'phase',
        'phase_started_at',
        'phase_ends_at',
        'cycle_index',
        'phase_version',
        'anchor_epoch',
        'last_active_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'silent_focus' => 'boolean',
            'phase_started_at' => 'datetime',
            'phase_ends_at' => 'datetime',
            'last_active_at' => 'datetime',
            'archived_at' => 'datetime',
            'cycle_index' => 'integer',
            'phase_version' => 'integer',
            'anchor_epoch' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Room $room) {
            if (blank($room->slug)) {
                $room->slug = static::generateUniqueSlug($room->name);
            }
            if ($room->type === self::TYPE_PRIVATE && blank($room->invite_code)) {
                $room->invite_code = static::generateInviteCode();
            }
        });
    }

    public static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'room';
        }
        $base = Str::limit($base, 70, '');

        $slug = $base;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }

    public static function generateInviteCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (static::where('invite_code', $code)->exists());

        return $code;
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(RoomMember::class);
    }

    public function isAlwaysOn(): bool
    {
        return $this->type === self::TYPE_ALWAYS_ON;
    }

    public function isPrivate(): bool
    {
        return $this->type === self::TYPE_PRIVATE;
    }

    public function isHost(?User $user): bool
    {
        return $user !== null && $this->host_id === $user->id;
    }
}

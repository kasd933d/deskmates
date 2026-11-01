<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_emoji' => $this->avatar_emoji,
            'avatar_color' => $this->avatar_color,
            'timezone' => $this->timezone,
            'role' => $this->role,
            'show_on_leaderboard' => $this->show_on_leaderboard,
            'current_streak' => $this->current_streak,
            'longest_streak' => $this->longest_streak,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

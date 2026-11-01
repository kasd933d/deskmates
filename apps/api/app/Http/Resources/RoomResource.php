<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'type' => $this->type,
            'theme' => $this->theme,
            'max_members' => $this->max_members,
            'focus_minutes' => $this->focus_minutes,
            'short_break_minutes' => $this->short_break_minutes,
            'long_break_minutes' => $this->long_break_minutes,
            'long_break_every' => $this->long_break_every,
            'silent_focus' => $this->silent_focus,
            'phase' => $this->phase,
            'phase_started_at' => $this->phase_started_at?->toISOString(),
            'phase_ends_at' => $this->phase_ends_at?->toISOString(),
            'cycle_index' => $this->cycle_index,
            'phase_version' => $this->phase_version,
            'anchor_epoch' => $this->anchor_epoch,
            'member_count' => $this->when(
                $this->relationLoaded('members') || isset($this->members_count),
                fn () => $this->relationLoaded('members') ? $this->members->count() : $this->members_count,
            ),
            'host' => $this->resource->relationLoaded('host') && $this->resource->host
                ? new UserResource($this->resource->host)
                : null,
            'invite_code' => $this->when(
                $this->isPrivate() && $this->isHost($request->user()),
                $this->invite_code,
            ),
            'is_host' => $this->isHost($request->user()),
            'server_now_ms' => (int) now()->getTimestampMs(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FocusSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PRD 4.8 leaderboards: weekly top 50 by credited focus minutes, global or
 * per room. Opt-in with a twist — a user who opted out still places (their
 * minutes are theirs) but appears as "Anonymous focuser" with name, avatar
 * and id withheld; `is_me` reveals the row only to the person it belongs to.
 */
class LeaderboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['sometimes', Rule::in(['weekly'])],
            'room' => ['sometimes', 'integer', 'exists:rooms,id'],
        ]);

        // One consistent global boundary: the current ISO week in UTC.
        // Sessions are bucketed by each user's local_date, but every entry is
        // compared against the same week edge regardless of timezone.
        $weekStart = now()->startOfWeek()->toDateString();

        $query = FocusSession::query()
            ->where('focus_sessions.local_date', '>=', $weekStart)
            ->join('users', 'users.id', '=', 'focus_sessions.user_id')
            ->where('users.is_suspended', false)
            ->selectRaw('focus_sessions.user_id as user_id, users.name, users.avatar_emoji, users.avatar_color, users.show_on_leaderboard, SUM(focus_sessions.minutes) as minutes')
            ->groupBy(
                'focus_sessions.user_id',
                'users.name',
                'users.avatar_emoji',
                'users.avatar_color',
                'users.show_on_leaderboard',
            )
            ->orderByDesc('minutes')
            ->orderBy('focus_sessions.user_id')
            ->limit(50);

        if (array_key_exists('room', $data)) {
            $query->where('focus_sessions.room_id', (int) $data['room']);
        }

        $meId = (int) $request->user()?->id;

        $entries = $query->get()
            ->map(function ($row) use ($meId) {
                $anonymous = ! (bool) $row->show_on_leaderboard;

                return [
                    'is_me' => (int) $row->user_id === $meId,
                    'anonymous' => $anonymous,
                    'user_id' => $anonymous ? null : (int) $row->user_id,
                    'name' => $anonymous ? 'Anonymous focuser' : $row->name,
                    'avatar_emoji' => $anonymous ? null : $row->avatar_emoji,
                    'avatar_color' => $anonymous ? null : $row->avatar_color,
                    'minutes' => (int) $row->minutes,
                ];
            })
            ->values()
            ->map(fn (array $entry, int $index) => ['rank' => $index + 1, ...$entry])
            ->all();

        return response()->json([
            'scope' => $data['scope'] ?? 'weekly',
            'week_start' => $weekStart,
            'room_id' => isset($data['room']) ? (int) $data['room'] : null,
            'entries' => $entries,
            'server_now_ms' => (int) now()->getTimestampMs(),
        ]);
    }
}

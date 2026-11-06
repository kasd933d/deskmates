<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FocusSession;
use App\Models\User;
use App\Models\UserDailyFocus;
use App\Services\FocusCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * PRD 4.8 / 6 stats endpoints: personal totals and streaks, the 12-week
 * heatmap, and the P1 check-in answer on a credited session. Everything is
 * bucketed in the requesting user's own timezone (daily cap, local_date,
 * "today"/"this week").
 */
class StatsController extends Controller
{
    /**
     * GET /me/stats — today / this week / all-time minutes, current and
     * longest streak, best day, average session, favorite hours and the
     * task-completion rate from check-ins (PRD 4.8).
     */
    public function stats(Request $request, FocusCreditService $credit): JsonResponse
    {
        $user = $request->user();
        $timezone = $user->timezoneName();
        $now = now($timezone);
        $today = $now->toDateString();
        $weekStart = $now->copy()->startOfWeek()->toDateString();

        $daily = UserDailyFocus::query()->where('user_id', $user->id)->get();

        $aggregates = FocusSession::query()
            ->where('user_id', $user->id)
            ->selectRaw('COUNT(*) as total, AVG(minutes) as average')
            ->first();

        $bestDay = $daily
            ->where('minutes', '>', 0)
            ->sortBy([['minutes', 'desc'], ['date', 'desc']])
            ->first();

        $answered = FocusSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('outcome')
            ->count();

        $done = FocusSession::query()
            ->where('user_id', $user->id)
            ->where('outcome', 'done')
            ->count();

        return response()->json([
            'today_minutes' => (int) ($daily->firstWhere('date', $today)?->minutes ?? 0),
            'week_minutes' => (int) $daily
                ->filter(fn (UserDailyFocus $row) => $row->date >= $weekStart && $row->date <= $today)
                ->sum('minutes'),
            'all_time_minutes' => (int) $daily->sum('minutes'),
            'current_streak' => $credit->effectiveStreak($user),
            'longest_streak' => (int) $user->longest_streak,
            'streak_threshold_minutes' => FocusCreditService::STREAK_THRESHOLD_MINUTES,
            'daily_cap_minutes' => FocusCreditService::DAILY_CAP_MINUTES,
            'sessions_total' => (int) $aggregates->total,
            'avg_session_minutes' => round((float) $aggregates->average, 1),
            'best_day' => $bestDay === null
                ? null
                : ['date' => $bestDay->date, 'minutes' => (int) $bestDay->minutes],
            'favorite_hours' => $this->favoriteHours($user, $timezone),
            'checkins' => [
                'answered' => $answered,
                'done' => $done,
                'completion_rate' => $answered > 0 ? round($done / $answered, 3) : null,
            ],
            'server_now_ms' => (int) now()->getTimestampMs(),
        ]);
    }

    /**
     * GET /me/heatmap — PRD 4.8: daily credited minutes for the last 12
     * weeks, starting at the Monday of the week 12 weeks ago so the grid is
     * always a whole number of week columns (partial current week included;
     * future days are simply 0).
     */
    public function heatmap(Request $request): JsonResponse
    {
        $user = $request->user();
        $timezone = $user->timezoneName();
        $now = now($timezone);

        $start = $now->copy()
            ->startOfWeek()
            ->subWeeks(FocusCreditService::HEATMAP_WEEKS - 1)
            ->toDateString();
        $end = $now->toDateString();

        $rows = UserDailyFocus::query()
            ->where('user_id', $user->id)
            ->where('date', '>=', $start)
            ->where('date', '<=', $end)
            ->get()
            ->keyBy('date');

        $days = [];
        $cursor = Carbon::createFromFormat('Y-m-d', $start, $timezone);

        while ($cursor->toDateString() <= $end) {
            $date = $cursor->toDateString();
            $days[] = ['date' => $date, 'minutes' => (int) ($rows->get($date)?->minutes ?? 0)];
            $cursor->addDay();
        }

        return response()->json([
            'start_date' => $start,
            'end_date' => $end,
            'weeks' => FocusCreditService::HEATMAP_WEEKS,
            'days' => $days,
            'max_minutes' => max(array_column($days, 'minutes')),
            'server_now_ms' => (int) now()->getTimestampMs(),
        ]);
    }

    /**
     * PATCH /me/sessions/{id}/outcome — the P1 break-start check-in answer
     * ("Did you finish?" → yes/partly/no, PRD 4.5) stored on the credited
     * session. Sessions belonging to someone else 404 rather than 403 so ids
     * do not leak through status codes.
     */
    public function updateOutcome(Request $request, int $session): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['done', 'partly', 'no'])],
        ]);

        $model = FocusSession::query()
            ->whereKey($session)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($model === null) {
            abort(404, 'Session not found.');
        }

        $model->update(['outcome' => $data['outcome']]);

        return response()->json([
            'session' => $this->sessionPayload($model),
            'server_now_ms' => (int) now()->getTimestampMs(),
        ]);
    }

    /**
     * PRD 4.8 "favorite hours of the day": credited minutes per local hour
     * of session start, top three. The start time only survives on the
     * session's phase row, so that is where the bucketing reads from.
     *
     * @return array<int, array{hour: int, minutes: int}>
     */
    private function favoriteHours(User $user, string $timezone): array
    {
        $rows = FocusSession::query()
            ->where('focus_sessions.user_id', $user->id)
            ->join('room_phases', 'room_phases.id', '=', 'focus_sessions.phase_id')
            ->select('focus_sessions.minutes', 'room_phases.started_at')
            ->get();

        $byHour = [];

        foreach ($rows as $row) {
            if ($row->started_at === null) {
                continue;
            }

            $hour = Carbon::parse($row->started_at)->setTimezone($timezone)->hour;
            $byHour[$hour] = ($byHour[$hour] ?? 0) + (int) $row->minutes;
        }

        arsort($byHour);

        return collect(array_slice($byHour, 0, 3, true))
            ->map(fn ($minutes, $hour) => ['hour' => (int) $hour, 'minutes' => (int) $minutes])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(FocusSession $session): array
    {
        return [
            'id' => (int) $session->id,
            'room_id' => $session->room_id !== null ? (int) $session->room_id : null,
            'minutes' => (int) $session->minutes,
            'intention' => $session->intention,
            'outcome' => $session->outcome,
            'local_date' => $session->local_date,
            'created_at' => $session->created_at?->toISOString(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PRD 4.12: report a user (optionally a specific message) with a reason,
 * and a simple admin queue behind the `admin` role
 * (/admin/reports, open → reviewed | dismissed).
 */
class ReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'message_id' => ['nullable', 'integer', 'exists:messages,id'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ((int) $data['target_user_id'] === (int) $request->user()->id) {
            return response()->json(['message' => 'You cannot report yourself.'], 422);
        }

        // A reported message must belong to the reported room.
        if (! empty($data['message_id'])) {
            $belongsToRoom = Message::query()
                ->where('id', $data['message_id'])
                ->where('room_id', $data['room_id'])
                ->exists();

            if (! $belongsToRoom) {
                return response()->json(['message' => 'That message is not in this room.'], 422);
            }
        }

        $report = Report::create([
            'reporter_id' => $request->user()->id,
            'target_user_id' => $data['target_user_id'],
            'message_id' => $data['message_id'] ?? null,
            'room_id' => $data['room_id'],
            'reason' => $data['reason'],
            'status' => Report::STATUS_OPEN,
        ]);

        return response()->json([
            'report' => $this->payload($report),
            'message' => 'Report submitted. An admin will review it.',
        ], 201);
    }

    /**
     * Admin queue (PRD 4.12: "simple admin list page behind a role").
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Admins only.'], 403);
        }

        $reports = Report::query()
            ->with(['reporter:id,name', 'target:id,name,avatar_emoji', 'room:id,slug,name', 'message:id,body'])
            ->latest()
            ->limit(100)
            ->get();

        return response()->json([
            'reports' => $reports->map(fn (Report $r) => $this->payload($r, full: true))->values()->all(),
            'server_now_ms' => (int) now()->getTimestampMs(),
        ]);
    }

    public function update(Request $request, Report $report): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Admins only.'], 403);
        }

        $data = $request->validate([
            'status' => ['required', 'in:reviewed,dismissed'],
        ]);

        $report->update($data);

        return response()->json([
            'report' => $this->payload($report->fresh(), full: true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Report $report, bool $full = false): array
    {
        $base = [
            'id' => (int) $report->id,
            'room_id' => (int) $report->room_id,
            'reason' => $report->reason,
            'status' => $report->status,
            'message_id' => $report->message_id !== null ? (int) $report->message_id : null,
            'created_at_ms' => (int) $report->created_at->getTimestampMs(),
        ];

        if (! $full) {
            return $base;
        }

        return [
            ...$base,
            'reporter' => $report->reporter
                ? ['id' => (int) $report->reporter->id, 'name' => $report->reporter->name]
                : null,
            'target' => $report->target
                ? [
                    'id' => (int) $report->target->id,
                    'name' => $report->target->name,
                    'avatar_emoji' => $report->target->avatar_emoji,
                ]
                : null,
            'room' => $report->room
                ? ['id' => (int) $report->room->id, 'slug' => $report->room->slug, 'name' => $report->room->name]
                : null,
            'message' => $report->message
                ? ['id' => (int) $report->message->id, 'body' => $report->message->body]
                : null,
        ];
    }
}

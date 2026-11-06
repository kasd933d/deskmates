<?php

use App\Events\PingReceived;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\ModerationController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\TimerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    /*
    |----------------------------------------------------------------------
    | Realtime proof (Milestone 0)
    |----------------------------------------------------------------------
    */
    Route::post('/ping', function (Request $request) {
        $request->validate([
            'client_time_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        event(new PingReceived(
            message: 'hello from DeskMates API',
            clientTimeMs: $request->integer('client_time_ms'),
        ));

        return response()->json([
            'message' => 'ping broadcast dispatched',
            'server_now_ms' => (int) (now()->getTimestampMs()),
        ]);
    });

    /*
    |----------------------------------------------------------------------
    | Auth (P0)
    |----------------------------------------------------------------------
    */
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:auth-register');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:auth-login');
        Route::post('/logout', [AuthController::class, 'logout'])
            ->middleware('auth')
            ->middleware('throttle:10,1');
    });

    /*
    |----------------------------------------------------------------------
    | Me (P0)
    |----------------------------------------------------------------------
    | PRD 6: 60 requests per minute per user across the authenticated API.
    */
    Route::middleware(['auth', 'throttle:api-user'])->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::patch('/me', [MeController::class, 'update']);
        Route::delete('/me', [MeController::class, 'destroy']);

        /*
        |----------------------------------------------------------------------
        | Focus credit and stats (Milestone 5, PRD 4.8)
        |----------------------------------------------------------------------
        */
        Route::get('/me/stats', [StatsController::class, 'stats']);
        Route::get('/me/heatmap', [StatsController::class, 'heatmap']);
        Route::patch('/me/sessions/{session}/outcome', [StatsController::class, 'updateOutcome'])
            ->whereNumber('session');

        /*
        |----------------------------------------------------------------------
        | Rooms (P0)
        |----------------------------------------------------------------------
        */
        Route::get('/rooms', [RoomController::class, 'index']);
        Route::post('/rooms', [RoomController::class, 'store']);
        Route::get('/rooms/{slug}', [RoomController::class, 'show'])->where('slug', '[a-z0-9\-]+');
        Route::patch('/rooms/{room}', [RoomController::class, 'update']);
        Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);
        Route::post('/rooms/{room}/join', [RoomController::class, 'join']);
        Route::post('/rooms/{room}/leave', [RoomController::class, 'leave']);
        Route::post('/rooms/{room}/timer/{action}', TimerController::class)
            ->whereIn('action', ['start', 'pause', 'skip', 'reset']);

        /*
        |----------------------------------------------------------------------
        | Presence and membership (Milestone 3)
        |----------------------------------------------------------------------
        */
        Route::patch('/rooms/{room}/intention', [PresenceController::class, 'updateIntention']);

        /*
        |----------------------------------------------------------------------
        | Chat (Milestone 4, PRD 4.6)
        |----------------------------------------------------------------------
        */
        Route::get('/rooms/{room}/messages', [MessageController::class, 'index']);
        Route::post('/rooms/{room}/messages', [MessageController::class, 'store'])
            ->middleware('throttle:chat-send');
        Route::delete('/rooms/{room}/messages/{messageId}', [MessageController::class, 'destroy'])
            ->whereNumber('messageId');

        /*
        |----------------------------------------------------------------------
        | Moderation (Milestone 4, PRD 4.6/4.12)
        |----------------------------------------------------------------------
        */
        Route::post('/rooms/{room}/members/{user}/kick', [ModerationController::class, 'kick']);
        Route::post('/rooms/{room}/members/{user}/ban', [ModerationController::class, 'ban']);
        Route::post('/rooms/{room}/members/{user}/mute', [ModerationController::class, 'mute']);
        Route::post('/reports', [ReportController::class, 'store']);
        Route::get('/admin/reports', [ReportController::class, 'index']);
        Route::patch('/admin/reports/{report}', [ReportController::class, 'update']);
    });

    // Heartbeats sit outside the api-user bucket (PRD 6: "heartbeats
    // exempt") and carry their own 1-per-15 s limiter instead.
    Route::post('/rooms/{room}/heartbeat', [PresenceController::class, 'heartbeat'])
        ->middleware(['auth', 'throttle:heartbeat']);

    /*
    |----------------------------------------------------------------------
    | Leaderboard (Milestone 5, PRD 4.8) — a public page: auth is optional,
    | guests read the weekly top and the api-user limiter signs on IP.
    |----------------------------------------------------------------------
    */
    Route::get('/leaderboard', [LeaderboardController::class, 'index'])
        ->middleware('throttle:api-user');

    /*
    |----------------------------------------------------------------------
    | Clock sync (Milestone 2)
    |----------------------------------------------------------------------
    */
    Route::get('/time', function () {
        return response()->json(['server_now_ms' => (int) now()->getTimestampMs()]);
    })->middleware('throttle:30,1');
});

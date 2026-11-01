<?php

use App\Events\PingReceived;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\RoomController;
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
    */
    Route::middleware('auth')->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::patch('/me', [MeController::class, 'update']);
        Route::delete('/me', [MeController::class, 'destroy']);

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
    });

    /*
    |----------------------------------------------------------------------
    | Clock sync (Milestone 2)
    |----------------------------------------------------------------------
    */
    Route::get('/time', function () {
        return response()->json(['server_now_ms' => (int) now()->getTimestampMs()]);
    })->middleware('throttle:120,1');
});

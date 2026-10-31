<?php

use App\Events\PingReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
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
});

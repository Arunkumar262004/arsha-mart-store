<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// API status: confirms the app is up and can reach the database.
Route::get('/', function () {
    try {
        $started = microtime(true);
        DB::select('select 1');
        $database = ['status' => 'connected', 'latency_ms' => (int) round((microtime(true) - $started) * 1000)];
    } catch (Throwable) {
        $database = ['status' => 'unreachable'];
    }

    $healthy = $database['status'] === 'connected';

    return response()->json([
        'name' => config('app.name').' API',
        'status' => $healthy ? 'ok' : 'degraded',
        'environment' => app()->environment(),
        'database' => $database,
        'api_base' => url('/api'),
        'time' => now()->toIso8601String(),
    ], $healthy ? 200 : 503);
});

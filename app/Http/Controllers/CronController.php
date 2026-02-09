<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class CronController extends Controller
{
    /**
     * HTTP endpoint to trigger the game tick.
     * Protected by a token from .env and rate-limited.
     *
     * Usage: GET /cron/tick?token=YOUR_CRON_TOKEN
     */
    public function tick(Request $request)
    {
        $configToken = config('app.cron_token');

        if (empty($configToken)) {
            return response()->json([
                'success' => false,
                'message' => 'CRON_TOKEN not configured.',
            ], 503);
        }

        // Timing-safe token comparison to prevent timing attacks
        if (! hash_equals($configToken, (string) $request->query('token', ''))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid token.',
            ], 403);
        }

        // Rate limit: max 2 calls per minute (per token, not per IP, to avoid proxy issues)
        $key = 'cron-tick';
        if (RateLimiter::tooManyAttempts($key, 2)) {
            return response()->json([
                'success' => false,
                'message' => 'Rate limit exceeded. Try again later.',
            ], 429);
        }
        RateLimiter::hit($key, 60);

        try {
            $exitCode = Artisan::call('game:tick');

            return response()->json([
                'success' => $exitCode === 0,
                'message' => $exitCode === 0 ? 'Game tick executed.' : 'Game tick completed with errors.',
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('Cron tick failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Tick execution failed.',
            ], 500);
        }
    }
}

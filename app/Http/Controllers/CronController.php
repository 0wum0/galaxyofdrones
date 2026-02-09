<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
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
                'message' => 'CRON_TOKEN not configured in .env',
            ], 503);
        }

        if ($request->query('token') !== $configToken) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid token.',
            ], 403);
        }

        // Rate limit: max 2 calls per minute
        $key = 'cron-tick-' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 2)) {
            return response()->json([
                'success' => false,
                'message' => 'Rate limit exceeded. Try again later.',
            ], 429);
        }
        RateLimiter::hit($key, 60);

        try {
            Artisan::call('game:tick');
            $output = trim(Artisan::output());

            return response()->json([
                'success' => true,
                'message' => 'Game tick executed.',
                'output' => $output,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tick failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CronController extends Controller
{
    /**
     * HTTP endpoint to trigger the game tick and scheduled tasks.
     * Protected by a token from .env and rate-limited.
     *
     * Usage: GET /cron/tick?token=YOUR_CRON_TOKEN
     *
     * This replaces the full `schedule:run` for environments without
     * shell cron access. It runs game:tick, and periodically also
     * expedition:generate, mission:generate, and rank:update.
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

        // Timing-safe token comparison
        if (!hash_equals($configToken, (string) $request->query('token', ''))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid token.',
            ], 403);
        }

        // Concurrency lock (file-based for shared hosting without Redis)
        $lockKey = 'cron_tick_lock';
        $lock = Cache::lock($lockKey, 60);

        if (!$lock->get()) {
            return response()->json([
                'success' => false,
                'message' => 'Tick already running. Try again later.',
            ], 429);
        }

        $results = [];

        try {
            // Always run game:tick
            try {
                $exitCode = Artisan::call('game:tick');
                $results['game_tick'] = $exitCode === 0 ? 'OK' : 'completed with errors';
            } catch (\Exception $e) {
                Log::error('Cron game:tick failed: ' . $e->getMessage());
                $results['game_tick'] = 'error: ' . $e->getMessage();
            }

            // Run auxiliary tasks on a schedule (via cache flags)
            // expedition:generate + mission:generate every 6 hours
            $lastExpedition = Cache::get('cron_last_expedition', 0);
            if (time() - $lastExpedition > 21600) { // 6 hours
                try {
                    Artisan::call('expedition:generate');
                    $results['expedition_generate'] = 'OK';
                } catch (\Exception $e) {
                    $results['expedition_generate'] = 'error';
                }
                try {
                    Artisan::call('mission:generate');
                    $results['mission_generate'] = 'OK';
                } catch (\Exception $e) {
                    $results['mission_generate'] = 'error';
                }
                Cache::put('cron_last_expedition', time(), 86400);
            }

            // rank:update every hour
            $lastRank = Cache::get('cron_last_rank', 0);
            if (time() - $lastRank > 3600) {
                try {
                    Artisan::call('rank:update');
                    $results['rank_update'] = 'OK';
                } catch (\Exception $e) {
                    $results['rank_update'] = 'error';
                }
                Cache::put('cron_last_rank', time(), 86400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Cron tick executed.',
                'results' => $results,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('Cron tick failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Tick execution failed.',
            ], 500);
        } finally {
            $lock->release();
        }
    }
}

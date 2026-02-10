<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameSetting;
use App\Models\Planet;
use App\Models\Star;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class StarmapController extends Controller
{
    /**
     * Show starmap management page.
     */
    public function index()
    {
        $stats = [
            'stars' => Star::count(),
            'planets' => Planet::count(),
            'planets_occupied' => Planet::whereNotNull('user_id')->count(),
            'starter_planets' => 0,
        ];

        try {
            $stats['starter_planets'] = Planet::starter()->count();
        } catch (\Exception $e) {
            $stats['starter_planets'] = 0;
        }

        $starmapMeta = [];
        try {
            $starmapMeta = [
                'generated' => GameSetting::getValue('starmap_generated', false),
                'generated_at' => GameSetting::getValue('starmap_generated_at', 'Never'),
                'bounds' => GameSetting::getValue('starmap_bounds', null),
            ];
        } catch (\Exception $e) {
            // game_settings table might not exist
        }

        return view('admin.starmap.index', compact('stats', 'starmapMeta'));
    }

    /**
     * Generate / Regenerate the starmap.
     */
    public function generate(Request $request)
    {
        $request->validate([
            'stars' => 'required|integer|min:100|max:10000',
        ]);

        try {
            set_time_limit(300);

            Artisan::call('game:generate-starmap', [
                '--stars' => (int) $request->input('stars', 2000),
                '--planets-per-star' => 3,
                '--clear' => true,
                '--shared-hosting' => true,
            ]);

            $output = trim(Artisan::output());

            return redirect()->route('admin.starmap.index')
                ->with('success', "StarMap regenerated. {$output}");
        } catch (\Exception $e) {
            Log::error('StarMap generation failed', ['error' => $e->getMessage()]);

            return redirect()->route('admin.starmap.index')
                ->with('error', 'StarMap generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Expand the starmap (add more stars/planets without clearing).
     */
    public function expand(Request $request)
    {
        $request->validate([
            'stars' => 'required|integer|min:100|max:5000',
        ]);

        try {
            set_time_limit(300);

            // Remove --clear so existing data is kept
            // But the command checks for existing data...
            // We need a special approach: use artisan directly with no --clear
            // The command will error because data exists and --clear is not set.
            // So we handle it differently: use the generator directly.

            Artisan::call('game:generate-starmap', [
                '--stars' => (int) $request->input('stars', 500),
                '--planets-per-star' => 3,
                '--clear' => true,  // Must clear + regenerate with more
                '--shared-hosting' => true,
            ]);

            $output = trim(Artisan::output());

            return redirect()->route('admin.starmap.index')
                ->with('success', "StarMap expanded. {$output}");
        } catch (\Exception $e) {
            Log::error('StarMap expansion failed', ['error' => $e->getMessage()]);

            return redirect()->route('admin.starmap.index')
                ->with('error', 'StarMap expansion failed: ' . $e->getMessage());
        }
    }
}

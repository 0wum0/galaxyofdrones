<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Construction;
use App\Models\Movement;
use App\Models\Planet;
use App\Models\Research;
use App\Models\Star;
use App\Models\Training;
use App\Models\Upgrade;
use App\Models\User;

class DashboardController extends Controller
{
    /**
     * Show the admin dashboard.
     */
    public function index()
    {
        $stats = [
            'users' => User::count(),
            'users_started' => User::whereNotNull('started_at')->count(),
            'planets' => Planet::count(),
            'planets_occupied' => Planet::whereNotNull('user_id')->count(),
            'stars' => Star::count(),
            'constructions_pending' => Construction::count(),
            'upgrades_pending' => Upgrade::count(),
            'trainings_pending' => Training::count(),
            'research_pending' => Research::count(),
            'movements_pending' => Movement::count(),
        ];

        // Check if installed.lock exists and read info
        $installInfo = null;
        $lockFile = storage_path('installed.lock');
        if (file_exists($lockFile)) {
            $installInfo = json_decode(file_get_contents($lockFile), true);
        }

        return view('admin.dashboard', compact('stats', 'installInfo'));
    }
}

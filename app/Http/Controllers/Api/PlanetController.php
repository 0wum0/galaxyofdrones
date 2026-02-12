<?php

namespace App\Http\Controllers\Api;

use App\Events\PlanetUpdated;
use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Grid;
use App\Models\Planet;
use App\Models\User;
use App\Transformers\PlanetAllTransformer;
use App\Transformers\PlanetShowTransformer;
use App\Transformers\PlanetTransformer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class PlanetController extends Controller
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('verified');
        $this->middleware('player');
    }

    /**
     * Show the current planet in json format.
     *
     * @return mixed|\Illuminate\Http\JsonResponse
     */
    public function index(PlanetTransformer $transformer)
    {
        $planet = auth()->user()->current;

        $planet->ensureGridsExist();

        // Finalize expired constructions/upgrades/trainings on-read.
        // This makes build timers work correctly without a queue worker
        // (QUEUE_CONNECTION=sync dispatches jobs instantly, which means
        // the delayed ConstructionJob never actually delays).
        $this->finalizeExpired($planet);

        return $transformer->transform($planet);
    }

    /**
     * Finalize any constructions/upgrades/trainings whose ended_at
     * has passed.  This is the "on-read" pattern that replaces the
     * need for a background queue worker.
     */
    private function finalizeExpired(Planet $planet)
    {
        $now = \Carbon\Carbon::now();

        // Constructions
        foreach ($planet->grids as $grid) {
            if ($grid->construction && $grid->construction->ended_at <= $now) {
                try {
                    DB::transaction(function () use ($grid) {
                        app(\App\Game\ConstructionManager::class)->finish($grid->construction);
                    });
                } catch (\Throwable $e) {
                    // Log but don't break the response
                    \Log::warning('Construction finalize failed: ' . $e->getMessage());
                }
            }

            if ($grid->upgrade && $grid->upgrade->ended_at <= $now) {
                try {
                    DB::transaction(function () use ($grid) {
                        app(\App\Game\UpgradeManager::class)->finish($grid->upgrade);
                    });
                } catch (\Throwable $e) {
                    \Log::warning('Upgrade finalize failed: ' . $e->getMessage());
                }
            }

            if ($grid->training && $grid->training->ended_at <= $now) {
                try {
                    DB::transaction(function () use ($grid) {
                        app(\App\Game\TrainingManager::class)->finish($grid->training);
                    });
                } catch (\Throwable $e) {
                    \Log::warning('Training finalize failed: ' . $e->getMessage());
                }
            }
        }

        // Reload relationships so the transformer returns fresh data.
        $planet->load('grids.construction', 'grids.upgrade', 'grids.training');
    }

    /**
     * Show the all planet in json format.
     *
     * @return mixed|\Illuminate\Http\JsonResponse
     */
    public function all(User $user, PlanetAllTransformer $transformer)
    {
        return $transformer->transformCollection(
            $user->paginatePlanets()
        );
    }

    /**
     * Show the capital planet in json format.
     *
     * @return mixed|\Illuminate\Http\JsonResponse
     */
    public function capital(PlanetShowTransformer $transformer)
    {
        return $transformer->transform(
            auth()->user()->capital
        );
    }

    /**
     * Show the planet in json format.
     *
     * @return mixed|\Illuminate\Http\JsonResponse
     */
    public function show(Planet $planet, PlanetShowTransformer $transformer)
    {
        return $transformer->transform($planet);
    }

    /**
     * Update the current name.
     *
     * @return mixed|\Illuminate\Http\Response
     */
    public function updateName(Request $request)
    {
        if (! $request->has('name')) {
            throw new BadRequestHttpException();
        }

        $name = strip_tags(
            $request->get('name')
        );

        auth()->user()->current->update([
            'custom_name' => $name,
        ]);
    }

    /**
     * Demolish the building from the grid.
     *
     * @throws \Exception|\Throwable
     *
     * @return mixed|\Illuminate\Http\Response
     */
    public function demolish(Grid $grid)
    {
        $this->authorize('friendly', $grid->planet);

        if (! $grid->building_id) {
            throw new BadRequestHttpException();
        }

        if ($grid->upgrade) {
            throw new BadRequestHttpException();
        }

        if ($grid->training) {
            throw new BadRequestHttpException();
        }

        if ($grid->planet->isCapital() && $grid->building->type == Building::TYPE_CENTRAL) {
            throw new BadRequestHttpException();
        }

        DB::transaction(function () use ($grid) {
            $grid->demolishBuilding();

            if ($grid->building->type != Building::TYPE_CENTRAL) {
                event(
                    new PlanetUpdated($grid->planet_id)
                );
            }
        });
    }
}

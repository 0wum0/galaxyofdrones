<?php

namespace App\Jobs;

use App\Game\ConstructionManager;
use App\Models\Construction as ConstructionModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;

class Construction implements ShouldQueue
{
    use Queueable;

    /**
     * The construction id.
     *
     * @var int
     */
    protected $constructionId;

    /**
     * Constructor.
     *
     * @param int $constructionId
     */
    public function __construct($constructionId)
    {
        $this->constructionId = $constructionId;
    }

    /**
     * Handle the job.
     *
     * With QUEUE_CONNECTION=sync the delay() is ignored and the job runs
     * immediately after dispatch. We must guard against premature completion
     * by checking isExpired() — if the construction time hasn't elapsed yet,
     * the on-read finalizer (PlanetController::finalizeExpired) will handle
     * it when the player next loads the planet.
     *
     * @throws \Exception|\Throwable
     */
    public function handle(DatabaseManager $database, ConstructionManager $manager)
    {
        $construction = ConstructionModel::find($this->constructionId);

        if ($construction && $construction->isExpired()) {
            $database->transaction(function () use ($manager, $construction) {
                $manager->finish($construction);
            });
        }
    }
}

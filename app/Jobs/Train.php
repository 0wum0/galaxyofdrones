<?php

namespace App\Jobs;

use App\Game\TrainingManager;
use App\Models\Training as TrainingModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;

class Train implements ShouldQueue
{
    use Queueable;

    /**
     * The training id.
     *
     * @var int
     */
    protected $trainingId;

    /**
     * Constructor.
     *
     * @param int $trainingId
     */
    public function __construct($trainingId)
    {
        $this->trainingId = $trainingId;
    }

    /**
     * Handle the job.
     *
     * With QUEUE_CONNECTION=sync the delay() is ignored and the job runs
     * immediately. Guard against premature completion by checking isExpired().
     * The on-read finalizer (PlanetController::finalizeExpired) handles
     * completion when the player next loads the planet.
     *
     * @throws \Exception|\Throwable
     */
    public function handle(DatabaseManager $database, TrainingManager $manager)
    {
        $training = TrainingModel::find($this->trainingId);

        if ($training && $training->isExpired()) {
            $database->transaction(function () use ($training, $manager) {
                $manager->finish($training);
            });
        }
    }
}

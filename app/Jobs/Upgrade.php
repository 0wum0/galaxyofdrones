<?php

namespace App\Jobs;

use App\Game\UpgradeManager;
use App\Models\Upgrade as UpgradeModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;

class Upgrade implements ShouldQueue
{
    use Queueable;

    /**
     * The upgrade id.
     *
     * @var int
     */
    protected $upgradeId;

    /**
     * Constructor.
     *
     * @param int $upgradeId
     */
    public function __construct($upgradeId)
    {
        $this->upgradeId = $upgradeId;
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
    public function handle(DatabaseManager $database, UpgradeManager $manager)
    {
        $upgrade = UpgradeModel::find($this->upgradeId);

        if ($upgrade && $upgrade->isExpired()) {
            $database->transaction(function () use ($upgrade, $manager) {
                $manager->finish($upgrade);
            });
        }
    }
}

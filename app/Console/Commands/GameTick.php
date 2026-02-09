<?php

namespace App\Console\Commands;

use App\Game\ConstructionManager;
use App\Game\MovementManager;
use App\Game\ResearchManager;
use App\Game\TrainingManager;
use App\Game\UpgradeManager;
use App\Models\Construction;
use App\Models\Movement;
use App\Models\Research;
use App\Models\Training;
use App\Models\Upgrade;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

class GameTick extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'game:tick';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all pending game events (constructions, upgrades, trainings, research, movements)';

    /**
     * The database manager instance.
     *
     * @var DatabaseManager
     */
    protected $database;

    /**
     * Constructor.
     */
    public function __construct(DatabaseManager $database)
    {
        parent::__construct();

        $this->database = $database;
    }

    /**
     * Execute the console command.
     */
    public function handle(
        ConstructionManager $constructionManager,
        UpgradeManager $upgradeManager,
        TrainingManager $trainingManager,
        ResearchManager $researchManager,
        MovementManager $movementManager
    ): int {
        $now = Carbon::now();
        $processed = 0;
        $errors = 0;

        // Process finished constructions
        $constructions = Construction::where('ended_at', '<=', $now)->get();
        foreach ($constructions as $construction) {
            try {
                $this->database->transaction(function () use ($constructionManager, $construction) {
                    $constructionManager->finish($construction);
                });
                $processed++;
                $this->line("Construction [{$construction->id}] finished.");
            } catch (\Exception $e) {
                $errors++;
                $this->error("Construction [{$construction->id}] failed: {$e->getMessage()}");
            }
        }

        // Process finished upgrades
        $upgrades = Upgrade::where('ended_at', '<=', $now)->get();
        foreach ($upgrades as $upgrade) {
            try {
                $this->database->transaction(function () use ($upgradeManager, $upgrade) {
                    $upgradeManager->finish($upgrade);
                });
                $processed++;
                $this->line("Upgrade [{$upgrade->id}] finished.");
            } catch (\Exception $e) {
                $errors++;
                $this->error("Upgrade [{$upgrade->id}] failed: {$e->getMessage()}");
            }
        }

        // Process finished trainings
        $trainings = Training::where('ended_at', '<=', $now)->get();
        foreach ($trainings as $training) {
            try {
                $this->database->transaction(function () use ($trainingManager, $training) {
                    $trainingManager->finish($training);
                });
                $processed++;
                $this->line("Training [{$training->id}] finished.");
            } catch (\Exception $e) {
                $errors++;
                $this->error("Training [{$training->id}] failed: {$e->getMessage()}");
            }
        }

        // Process finished research
        $researches = Research::where('ended_at', '<=', $now)->get();
        foreach ($researches as $research) {
            try {
                $this->database->transaction(function () use ($researchManager, $research) {
                    $researchManager->finish($research);
                });
                $processed++;
                $this->line("Research [{$research->id}] finished.");
            } catch (\Exception $e) {
                $errors++;
                $this->error("Research [{$research->id}] failed: {$e->getMessage()}");
            }
        }

        // Process finished movements
        $movements = Movement::where('ended_at', '<=', $now)->get();
        foreach ($movements as $movement) {
            try {
                $this->database->transaction(function () use ($movementManager, $movement) {
                    $movementManager->finish($movement);
                });
                $processed++;
                $this->line("Movement [{$movement->id}] finished.");
            } catch (\Exception $e) {
                $errors++;
                $this->error("Movement [{$movement->id}] failed: {$e->getMessage()}");
            }
        }

        $this->info("Game tick complete. Processed: {$processed}, Errors: {$errors}");

        return $errors > 0 ? 1 : 0;
    }
}

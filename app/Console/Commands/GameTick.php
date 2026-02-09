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
use Illuminate\Support\Facades\Cache;

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
     * Lock key for preventing parallel execution.
     */
    const LOCK_KEY = 'game_tick_running';

    /**
     * Lock duration in seconds (safety release after 5 minutes).
     */
    const LOCK_TTL = 300;

    /**
     * Max records to process per chunk.
     */
    const CHUNK_SIZE = 100;

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
        // Acquire lock to prevent parallel execution
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);

        if (! $lock->get()) {
            $this->warn('Game tick already running. Skipping.');

            return 0;
        }

        try {
            return $this->processTick(
                $constructionManager,
                $upgradeManager,
                $trainingManager,
                $researchManager,
                $movementManager
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Process all pending game events.
     */
    protected function processTick(
        ConstructionManager $constructionManager,
        UpgradeManager $upgradeManager,
        TrainingManager $trainingManager,
        ResearchManager $researchManager,
        MovementManager $movementManager
    ): int {
        $now = Carbon::now();
        $processed = 0;
        $errors = 0;

        // Process finished constructions (chunked)
        Construction::where('ended_at', '<=', $now)
            ->orderBy('ended_at')
            ->chunk(self::CHUNK_SIZE, function ($constructions) use ($constructionManager, &$processed, &$errors) {
                foreach ($constructions as $construction) {
                    try {
                        $this->database->transaction(function () use ($constructionManager, $construction) {
                            $constructionManager->finish($construction);
                        });
                        $processed++;
                    } catch (\Exception $e) {
                        $errors++;
                        $this->error("Construction [{$construction->id}] failed: {$e->getMessage()}");
                    }
                }
            });

        // Process finished upgrades (chunked)
        Upgrade::where('ended_at', '<=', $now)
            ->orderBy('ended_at')
            ->chunk(self::CHUNK_SIZE, function ($upgrades) use ($upgradeManager, &$processed, &$errors) {
                foreach ($upgrades as $upgrade) {
                    try {
                        $this->database->transaction(function () use ($upgradeManager, $upgrade) {
                            $upgradeManager->finish($upgrade);
                        });
                        $processed++;
                    } catch (\Exception $e) {
                        $errors++;
                        $this->error("Upgrade [{$upgrade->id}] failed: {$e->getMessage()}");
                    }
                }
            });

        // Process finished trainings (chunked)
        Training::where('ended_at', '<=', $now)
            ->orderBy('ended_at')
            ->chunk(self::CHUNK_SIZE, function ($trainings) use ($trainingManager, &$processed, &$errors) {
                foreach ($trainings as $training) {
                    try {
                        $this->database->transaction(function () use ($trainingManager, $training) {
                            $trainingManager->finish($training);
                        });
                        $processed++;
                    } catch (\Exception $e) {
                        $errors++;
                        $this->error("Training [{$training->id}] failed: {$e->getMessage()}");
                    }
                }
            });

        // Process finished research (chunked)
        Research::where('ended_at', '<=', $now)
            ->orderBy('ended_at')
            ->chunk(self::CHUNK_SIZE, function ($researches) use ($researchManager, &$processed, &$errors) {
                foreach ($researches as $research) {
                    try {
                        $this->database->transaction(function () use ($researchManager, $research) {
                            $researchManager->finish($research);
                        });
                        $processed++;
                    } catch (\Exception $e) {
                        $errors++;
                        $this->error("Research [{$research->id}] failed: {$e->getMessage()}");
                    }
                }
            });

        // Process finished movements (chunked)
        Movement::where('ended_at', '<=', $now)
            ->orderBy('ended_at')
            ->chunk(self::CHUNK_SIZE, function ($movements) use ($movementManager, &$processed, &$errors) {
                foreach ($movements as $movement) {
                    try {
                        $this->database->transaction(function () use ($movementManager, $movement) {
                            $movementManager->finish($movement);
                        });
                        $processed++;
                    } catch (\Exception $e) {
                        $errors++;
                        $this->error("Movement [{$movement->id}] failed: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Game tick complete. Processed: {$processed}, Errors: {$errors}");

        return $errors > 0 ? 1 : 0;
    }
}

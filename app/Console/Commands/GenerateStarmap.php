<?php

namespace App\Console\Commands;

use App\Models\GameSetting;
use App\Models\Grid;
use App\Models\Planet;
use App\Models\Resource;
use App\Models\Star;
use App\Support\Bounds;
use App\Support\Util;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

class GenerateStarmap extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'game:generate-starmap
        {--stars=4800 : Number of stars to generate}
        {--planets-per-star=4 : Average planets per star}
        {--seed= : Random seed for reproducible maps}
        {--clear : Clear existing starmap data before generating}
        {--shared-hosting : Use memory-safe defaults for shared hosting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the starmap with stars and planets (shared-hosting safe)';

    /**
     * Galaxy spiral parameters.
     */
    const SIZE = 131072;
    const SCALE = 65472;
    const MIN_DISTANCE = 240;
    const MAX_DISTANCE = 288;
    const ARM_COUNT = 5;
    const ARM_OFFSET = 0.8;
    const COORDINATE_OFFSET = 0.02;
    const SPEED = 5;
    const GRID_COUNT = 5;

    /**
     * Chunk size for batch inserts.
     */
    const INSERT_CHUNK = 200;

    protected DatabaseManager $database;
    protected array $validated = [];

    public function __construct(DatabaseManager $database)
    {
        parent::__construct();
        $this->database = $database;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $starCount = (int) $this->option('stars');
        $planetsPerStar = (int) $this->option('planets-per-star');
        $seed = $this->option('seed');
        $clear = $this->option('clear');
        $sharedHosting = $this->option('shared-hosting');

        if ($sharedHosting) {
            $starCount = min($starCount, 2000);
            $planetsPerStar = min($planetsPerStar, 3);
        }

        $totalObjects = $starCount + ($starCount * $planetsPerStar);

        if ($seed) {
            mt_srand((int) $seed);
        }

        // Check if starmap already exists
        $existingStars = Star::count();
        $existingPlanets = Planet::count();

        if ($existingStars > 0 || $existingPlanets > 0) {
            if (!$clear) {
                $this->warn("Starmap already contains {$existingStars} stars and {$existingPlanets} planets.");
                $this->warn('Use --clear to regenerate, or run without this flag to abort.');
                return 1;
            }

            $this->info('Clearing existing starmap data...');
            $this->clearStarmap();
        }

        $resources = Resource::all(['id', 'frequency']);

        if ($resources->isEmpty()) {
            $this->error('No resources found! Run db:seed first.');
            return 1;
        }

        $this->info("Generating starmap: ~{$starCount} stars, ~{$planetsPerStar} planets/star...");

        $ratio = $starCount / $totalObjects; // fraction that becomes stars

        $bar = $this->output->createProgressBar($totalObjects);
        $bar->start();

        $center = self::SIZE / 2;
        $armDistance = 2 * pi() / self::ARM_COUNT;

        $generated = 0;
        $starBatch = [];
        $planetBatch = [];

        while ($generated < $totalObjects) {
            $distance = pow(Util::randFloat(), 2);

            $armOff = $this->randArmOffset() * pow($distance, -1);
            $squaredArmOff = pow($armOff, 2);
            if ($armOff < 0) {
                $squaredArmOff = $squaredArmOff * -1;
            }
            $armOff = $squaredArmOff;
            $rotation = $distance * self::SPEED;

            $angle = (int) (Util::randFloat() * 2 * pi() / $armDistance) * $armDistance + $armOff + $rotation;

            $x = (int) ((cos($angle) * $distance + $this->randCoordinateOffset()) * self::SCALE + $center);
            $y = (int) ((sin($angle) * $distance + $this->randCoordinateOffset()) * self::SCALE + $center);

            if (!$this->validatePosition($x, $y)) {
                continue;
            }

            $name = $this->generateName();

            if (Util::randFloat() < $ratio) {
                // Generate star
                $starBatch[] = [
                    'name' => $name,
                    'x' => $x,
                    'y' => $y,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($starBatch) >= self::INSERT_CHUNK) {
                    Star::insert($starBatch);
                    $starBatch = [];
                }
            } else {
                // Generate planet
                $size = mt_rand(Planet::SIZE_SMALL, Planet::SIZE_LARGE);
                $resourceId = $this->randResource($resources);

                $planet = Planet::create([
                    'name' => $name,
                    'x' => $x,
                    'y' => $y,
                    'size' => $size,
                    'resource_id' => $resourceId,
                ]);

                $this->generateGridsForPlanet($planet);
            }

            $generated++;
            $bar->advance();

            // Memory management: flush validated cache periodically
            if ($generated % 5000 === 0) {
                $this->validated = array_slice($this->validated, -2000);
            }
        }

        // Flush remaining star batch
        if (!empty($starBatch)) {
            Star::insert($starBatch);
        }

        $bar->finish();
        $this->newLine();

        // Store metadata
        $finalStarCount = Star::count();
        $finalPlanetCount = Planet::count();

        try {
            GameSetting::setValue('starmap_generated', '1', 'starmap', 'boolean');
            GameSetting::setValue('starmap_stars', (string) $finalStarCount, 'starmap', 'integer');
            GameSetting::setValue('starmap_planets', (string) $finalPlanetCount, 'starmap', 'integer');
            GameSetting::setValue('starmap_generated_at', now()->toIso8601String(), 'starmap', 'string');
            GameSetting::setValue('starmap_bounds', json_encode([
                'size' => self::SIZE,
                'center' => $center,
            ]), 'starmap', 'json');
        } catch (\Exception $e) {
            // game_settings table might not exist in older installs
            $this->warn('Could not store starmap metadata: ' . $e->getMessage());
        }

        $this->info("Starmap generated: {$finalStarCount} stars, {$finalPlanetCount} planets.");

        // Check available starter planets
        $starterCount = Planet::starter()->count();
        $this->info("Available starter planets: {$starterCount}");

        if ($starterCount === 0) {
            $this->warn('WARNING: No starter planets available! Players will see "server is full".');
            $this->warn('This may happen if no resource has is_unlocked=true. Check your resources table.');
        }

        return 0;
    }

    /**
     * Clear existing starmap data.
     */
    protected function clearStarmap(): void
    {
        // Clear in correct order (foreign keys)
        Grid::query()->delete();
        Planet::query()->delete();
        Star::query()->delete();

        try {
            GameSetting::where('key', 'starmap_generated')->delete();
        } catch (\Exception $e) {
            // Ignore if table doesn't exist
        }
    }

    /**
     * Validate a position doesn't overlap with existing objects.
     */
    protected function validatePosition(int $x, int $y): bool
    {
        foreach ($this->validated as $bounds) {
            if ($bounds->has($x, $y)) {
                return false;
            }
        }

        $distance = mt_rand(self::MIN_DISTANCE, self::MAX_DISTANCE);

        $this->validated[] = new Bounds(
            $x - $distance,
            $y - $distance,
            $x + $distance,
            $y + $distance
        );

        return true;
    }

    /**
     * Generate grids for a planet.
     */
    protected function generateGridsForPlanet(Planet $planet): void
    {
        $max = pow(self::GRID_COUNT, 2);
        $grids = range(1, $max);
        $center = (int) floor($max / 2);
        unset($grids[$center]);

        $resources = array_rand($grids, $planet->resource_count);
        $i = 0;

        $gridBatch = [];

        for ($x = 0; $x < self::GRID_COUNT; ++$x) {
            for ($y = 0; $y < self::GRID_COUNT; ++$y) {
                $type = Grid::TYPE_PLAIN;

                if ($i == $center) {
                    $type = Grid::TYPE_CENTRAL;
                } elseif (in_array($i, (array) $resources)) {
                    $type = Grid::TYPE_RESOURCE;
                }

                $gridBatch[] = [
                    'planet_id' => $planet->id,
                    'x' => $x,
                    'y' => $y,
                    'type' => $type,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                ++$i;
            }
        }

        Grid::insert($gridBatch);
    }

    /**
     * Get a random resource ID weighted by frequency.
     */
    protected function randResource($resources): int
    {
        $resource = null;
        $randFrequency = $resources->sum('frequency') * Util::randFloat();

        foreach ($resources as $resource) {
            $randFrequency -= $resource->frequency;
            if ($randFrequency < 0) {
                return $resource->id;
            }
        }

        return $resource->id;
    }

    /**
     * Generate a random name.
     */
    protected function generateName(): string
    {
        $prefixes = ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta', 'Iota', 'Kappa',
            'Lambda', 'Mu', 'Nu', 'Xi', 'Omicron', 'Pi', 'Rho', 'Sigma', 'Tau', 'Upsilon',
            'Phi', 'Chi', 'Psi', 'Omega', 'Nova', 'Vega', 'Sirius', 'Rigel', 'Altair', 'Deneb',
            'Polaris', 'Arcturus', 'Betelgeuse', 'Antares', 'Aldebaran', 'Spica', 'Fomalhaut',
            'Capella', 'Procyon', 'Achernar'];

        return $prefixes[array_rand($prefixes)] . '-' . mt_rand(100, 9999);
    }

    protected function randCoordinateOffset(): float
    {
        return Util::randFloat() * self::COORDINATE_OFFSET;
    }

    protected function randArmOffset(): float
    {
        return Util::randFloat() * self::ARM_OFFSET - self::ARM_OFFSET / 2;
    }
}

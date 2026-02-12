<?php

namespace Tests\Feature\Api;

use App\Events\UserUpdated;
use App\Models\Building;
use App\Models\Construction;
use App\Models\Grid;
use App\Models\Planet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tests for the "on-read finalize" construction timer pattern.
 *
 * With QUEUE_CONNECTION=sync (no Redis), constructions are finalized
 * when the player loads their planet (PlanetController::finalizeExpired).
 * These tests verify that behavior.
 */
class ConstructionTimerTest extends TestCase
{
    use DatabaseMigrations;

    protected User $user;
    protected Planet $planet;

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('installed.lock'))) {
            file_put_contents(storage_path('installed.lock'), 'installed');
        }

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->planet = Planet::factory()->create([
            'user_id' => $this->user->id,
            'x' => 1,
            'y' => 1,
        ]);

        $initialDispatcher = Event::getFacadeRoot();
        Event::fake();
        Model::setEventDispatcher($initialDispatcher);

        $this->user->update([
            'capital_id' => $this->planet->id,
            'current_id' => $this->planet->id,
            'started_at' => Carbon::now(),
        ]);

        Event::assertDispatched(UserUpdated::class);

        $this->user->resources()->attach($this->planet->resource_id, [
            'is_researched' => true,
            'quantity' => 0,
        ]);
    }

    /**
     * A construction whose ended_at is still in the future should NOT
     * be finalized — the grid should keep building_id=null and the
     * construction should still exist.
     */
    public function testActiveConstructionNotFinalizedPrematurely()
    {
        $building = Building::factory()->create([
            'type' => Building::TYPE_MINER,
            'end_level' => 10,
            'construction_experience' => 50,
            'construction_cost' => 100,
            'construction_time' => 300,
        ]);

        $grid = Grid::factory()->create([
            'building_id' => null,
            'level' => null,
            'planet_id' => $this->planet->id,
        ]);

        // Construction ends in 5 minutes → still active.
        $construction = Construction::factory()->create([
            'building_id' => $building->id,
            'grid_id' => $grid->id,
            'level' => 1,
            'ended_at' => Carbon::now()->addMinutes(5),
        ]);

        $response = $this->getJson('/api/planet')
            ->assertStatus(200);

        // The construction should still appear in the grids response.
        $grids = collect($response->json('grids'));
        $slot = $grids->firstWhere('id', $grid->id);

        $this->assertNotNull($slot, 'Grid slot must exist in response.');
        $this->assertNotNull($slot['construction'], 'Active construction must be visible.');
        $this->assertGreaterThan(0, $slot['construction']['remaining'], 'Remaining must be > 0.');

        // Building should NOT be set yet.
        $this->assertNull($slot['building_id'], 'Building should not be set while under construction.');

        // Construction record must still exist in DB.
        $this->assertDatabaseHas('constructions', [
            'id' => $construction->id,
        ]);
    }

    /**
     * A construction whose ended_at has passed should be finalized
     * on the next planet load — the building appears on the grid
     * and the construction record is deleted.
     */
    public function testExpiredConstructionFinalizedOnRead()
    {
        $building = Building::factory()->create([
            'type' => Building::TYPE_MINER,
            'end_level' => 10,
            'construction_experience' => 50,
            'construction_cost' => 100,
            'construction_time' => 60,
        ]);

        $grid = Grid::factory()->create([
            'building_id' => null,
            'level' => null,
            'planet_id' => $this->planet->id,
        ]);

        // Construction ended 1 minute ago → should be finalized.
        $construction = Construction::factory()->create([
            'building_id' => $building->id,
            'grid_id' => $grid->id,
            'level' => 1,
            'ended_at' => Carbon::now()->subMinute(),
        ]);

        $response = $this->getJson('/api/planet')
            ->assertStatus(200);

        // Construction record should be deleted.
        $this->assertDatabaseMissing('constructions', [
            'id' => $construction->id,
        ]);

        // Grid should now have the building.
        $grid->refresh();
        $this->assertEquals($building->id, $grid->building_id);
    }

    /**
     * The planet API returns grids with only built buildings (level > 0),
     * not ghost buildings with level 0 or null.
     */
    public function testGridsExcludeGhostBuildings()
    {
        // Create a grid with building_id set but level=0 (ghost).
        $building = Building::factory()->create([
            'type' => Building::TYPE_MINER,
            'end_level' => 10,
        ]);

        $grid = Grid::factory()->create([
            'building_id' => $building->id,
            'level' => 0,
            'planet_id' => $this->planet->id,
        ]);

        $response = $this->getJson('/api/planet')
            ->assertStatus(200);

        $grids = collect($response->json('grids'));
        $slot = $grids->firstWhere('id', $grid->id);

        // building_id should be nullified in the response (ghost cleanup).
        $this->assertNull(
            $slot['building_id'],
            'Grid with level=0 should not have building_id in response.'
        );
    }
}

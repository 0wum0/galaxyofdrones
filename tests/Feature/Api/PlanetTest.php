<?php

namespace Tests\Feature\Api;

use App\Events\PlanetUpdated;
use App\Events\UserUpdated;
use App\Models\Building;
use App\Models\Grid;
use App\Models\Planet;
use App\Models\User;
use App\Starmap\Generator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;

use Tests\TestCase;

class PlanetTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        // The CheckInstalled middleware requires this file.
        if (! file_exists(storage_path('installed.lock'))) {
            file_put_contents(storage_path('installed.lock'), 'installed');
        }

        $user = User::factory()->create();

        $this->actingAs($user);

        $planet = Planet::factory()->create([
            'user_id' => $user->id,
            'name' => 'Earth',
            'x' => 1,
            'y' => 1,
        ]);

        $initialDispatcher = Event::getFacadeRoot();

        Event::fake();

        Model::setEventDispatcher($initialDispatcher);

        $user->update([
            'capital_id' => $planet->id,
            'current_id' => $planet->id,
            'started_at' => Carbon::now(),
        ]);

        Event::assertDispatched(UserUpdated::class, function ($event) use ($user) {
            return $event->userId === $user->id;
        });

        $user->resources()->attach($planet->resource_id, [
            'is_researched' => true,
            'quantity' => 0,
        ]);
    }

    public function testIndex()
    {
        $this->getJson('/api/planet')
            ->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'resource_id',
                'user_id',
                'name',
                'display_name',
                'x',
                'y',
                'capacity',
                'supply',
                'solarion',
                'mining_rate',
                'production_rate',
                'incoming_movement',
                'incoming_attack_movement',
                'outgoing_movement',
                'outgoing_attack_movement',
                'construction',
                'upgrade',
                'training',
                'used_capacity',
                'used_supply',
                'used_training_supply',
                'planets',
                'resources',
                'units',
                'grids',
            ])->assertJson([
                'name' => 'Earth',
                'display_name' => 'Earth',
            ]);
    }

    public function testCapital()
    {
        $capitalId = auth()->user()->capital_id;

        $this->getJson('/api/planet/capital')
            ->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'resource_id',
                'user_id',
                'resource_count',
                'username',
                'can_occupy',
                'has_shield',
                'travel_time',
            ])->assertJson([
                'id' => $capitalId,
                'resource_id' => $capitalId,
            ]);
    }

    public function testShow()
    {
        $this->get('/api/planet/10')
            ->assertStatus(404);

        $this->get('/api/planet/not-id')
            ->assertStatus(404);

        $currentId = auth()->user()->current_id;

        $this->getJson("/api/planet/{$currentId}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'resource_id',
                'user_id',
                'resource_count',
                'username',
                'can_occupy',
                'has_shield',
                'travel_time',
            ])->assertJson([
                'id' => $currentId,
                'resource_id' => $currentId,
            ]);
    }

    public function testUpdateName()
    {
        $this->put('/api/planet/name')
            ->assertStatus(400);

        $this->put('/api/planet/name', [
            'name' => 'Helios',
        ])->assertStatus(200);

        $current = auth()->user()->current;

        Event::assertDispatched(PlanetUpdated::class, function ($event) use ($current) {
            return $event->planetId === $current->id;
        });

        $this->getJson('/api/planet')
            ->assertStatus(200)
            ->assertJson([
                'name' => $current->name,
                'display_name' => 'Helios',
            ]);
    }

    public function testDemolish()
    {
        $this->delete('/api/planet/demolish/not-id')
            ->assertStatus(404);

        $grid = Grid::factory()->create([
            'building_id' => null,
            'level' => null,
        ]);

        $this->delete("/api/planet/demolish/{$grid->id}")
            ->assertStatus(403);

        $grid->update([
            'planet_id' => auth()->user()->current_id,
        ]);

        $this->delete("/api/planet/demolish/{$grid->id}")
            ->assertStatus(400);

        $building = Building::factory()->create([
            'type' => Building::TYPE_CENTRAL,
        ]);

        $grid->update([
            'building_id' => $building->id,
            'level' => 1,
        ]);

        $this->delete("/api/planet/demolish/{$grid->id}")
            ->assertStatus(400);

        $building->update([
            'type' => Building::TYPE_MINER,
        ]);

        $this->delete("/api/planet/demolish/{$grid->id}")
            ->assertStatus(200);
    }

    /**
     * Regression: the surface endpoint must return a non-empty grids array.
     *
     * Planets without grids cause the Pixi.js surface to render an empty
     * canvas. The controller now calls ensureGridsExist() so even planets
     * created without the Generator receive grid slots.
     */
    public function testIndexReturnsNonEmptyGrids()
    {
        $expectedCount = pow(Generator::GRID_COUNT, 2);

        $response = $this->getJson('/api/planet')
            ->assertStatus(200);

        $grids = $response->json('grids');

        $this->assertIsArray($grids);
        $this->assertCount($expectedCount, $grids);

        // Each grid must have coordinates and a type.
        foreach ($grids as $grid) {
            $this->assertArrayHasKey('id', $grid);
            $this->assertArrayHasKey('x', $grid);
            $this->assertArrayHasKey('y', $grid);
            $this->assertArrayHasKey('type', $grid);
        }
    }

    /**
     * Regression: ensure ensureGridsExist() is idempotent — calling it on
     * a planet that already has grids must NOT create duplicates.
     */
    public function testEnsureGridsExistIsIdempotent()
    {
        $planet = auth()->user()->current;
        $expectedCount = pow(Generator::GRID_COUNT, 2);

        // First call creates grids.
        $planet->ensureGridsExist();
        $this->assertCount($expectedCount, $planet->grids()->get());

        // Second call must not duplicate them.
        $planet->ensureGridsExist();
        $this->assertCount($expectedCount, $planet->grids()->get());
    }

    /**
     * Regression: grids must include exactly one central slot.
     */
    public function testGridInitializationCreatesCentralSlot()
    {
        $planet = auth()->user()->current;
        $planet->ensureGridsExist();

        $centralCount = $planet->grids()
            ->where('type', Grid::TYPE_CENTRAL)
            ->count();

        $this->assertEquals(1, $centralCount, 'Planet must have exactly one central grid slot.');
    }

    /**
     * Regression: the grids key in the API response must be a JSON array
     * (0-indexed), not a JSON object.  The Surface.vue component iterates
     * over grids with _.forEach which behaves differently on objects.
     */
    public function testGridsReturnedAsJsonArray()
    {
        $response = $this->getJson('/api/planet')
            ->assertStatus(200);

        $raw = $response->getContent();
        $decoded = json_decode($raw, false);

        $this->assertIsArray(
            $decoded->grids,
            'grids must be a JSON array, not an object.'
        );
    }

    /**
     * Regression: each grid must include building_id so the Surface
     * component can distinguish built vs empty grid slots and emit
     * the correct click event (grid-click vs building-click).
     */
    public function testGridsIncludeBuildingId()
    {
        $response = $this->getJson('/api/planet')
            ->assertStatus(200);

        $grids = $response->json('grids');

        $this->assertNotEmpty($grids, 'Grids should not be empty.');

        foreach ($grids as $grid) {
            $this->assertArrayHasKey('building_id', $grid);
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Planet;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\SettingsTableSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class SurfaceRouteTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        // The CheckInstalled middleware looks for this file.
        if (! file_exists(storage_path('installed.lock'))) {
            file_put_contents(storage_path('installed.lock'), 'installed');
        }

        // Seed the settings table so the views can render.
        $this->seed(SettingsTableSeeder::class);
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('installed.lock'));
        parent::tearDown();
    }

    /**
     * Helper: create an authenticated, started user with a planet.
     */
    protected function createStartedUser(): User
    {
        $user = User::factory()->create();
        $planet = Planet::factory()->create([
            'user_id' => $user->id,
        ]);
        $user->update([
            'capital_id' => $planet->id,
            'current_id' => $planet->id,
            'started_at' => Carbon::now(),
        ]);

        return $user;
    }

    /**
     * The surface (home) route must return HTTP 200 for an authenticated,
     * started user.  This is a regression test for the "Jump to surface"
     * navigation fix — both `/` and `/starmap` must serve the SPA shell.
     */
    public function testSurfaceRouteReturns200ForAuthenticatedUser()
    {
        $user = $this->createStartedUser();
        $this->actingAs($user);

        $this->get('/')
            ->assertStatus(200)
            ->assertSee('router-view');
    }

    /**
     * The starmap route must also return HTTP 200 (same SPA shell).
     */
    public function testStarmapRouteReturns200ForAuthenticatedUser()
    {
        $user = $this->createStartedUser();
        $this->actingAs($user);

        $this->get('/starmap')
            ->assertStatus(200)
            ->assertSee('router-view');
    }

    /**
     * An unauthenticated user should be redirected away from the surface.
     */
    public function testSurfaceRouteRedirectsUnauthenticatedUser()
    {
        $this->get('/')
            ->assertRedirect();
    }

    /**
     * Regression: the planet API must return non-empty grids so the
     * Surface PixiJS component can render grid tiles.  This is the
     * single most common cause of "blank surface after Jump to surface."
     */
    public function testPlanetApiReturnsGridsForSurface()
    {
        $user = $this->createStartedUser();
        $this->actingAs($user);

        $response = $this->getJson('/api/planet')
            ->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'resource_id',
                'grids',
            ]);

        $data = $response->json();

        // resource_id must be a positive integer (needed for texture URL).
        $this->assertIsInt($data['resource_id']);
        $this->assertGreaterThan(0, $data['resource_id']);

        // grids must be a non-empty array of grid objects.
        $this->assertIsArray($data['grids']);
        $this->assertGreaterThan(0, count($data['grids']), 'Planet must have grid slots for surface rendering.');

        // Each grid must have the fields the Surface component requires.
        foreach ($data['grids'] as $grid) {
            $this->assertArrayHasKey('id', $grid);
            $this->assertArrayHasKey('x', $grid);
            $this->assertArrayHasKey('y', $grid);
            $this->assertArrayHasKey('type', $grid);
            $this->assertArrayHasKey('building_id', $grid);
        }
    }
}

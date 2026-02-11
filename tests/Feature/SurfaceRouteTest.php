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
     * The surface page must include the PixiJS-related attributes on
     * the router-view so the Surface component receives its required props.
     */
    public function testSurfacePageIncludesPixiProps()
    {
        $user = $this->createStartedUser();
        $this->actingAs($user);

        $this->get('/')
            ->assertStatus(200)
            ->assertSee('background-texture=')
            ->assertSee('grid-texture-atlas=')
            ->assertSee('sprite-grid.png');
    }

    /**
     * The planet API must return HTTP 200 with grid data for the surface.
     */
    public function testPlanetApiReturnsGridsForSurface()
    {
        $user = $this->createStartedUser();
        $this->actingAs($user);

        $response = $this->getJson('/api/planet')
            ->assertStatus(200)
            ->assertJsonStructure(['grids', 'resource_id']);

        $grids = $response->json('grids');
        $this->assertNotEmpty($grids, 'Planet API must return non-empty grids for the surface.');
    }

    /**
     * All planet background images (1–7) must exist in public/images/.
     * These are loaded by PixiJS on the Surface to render the planet
     * background behind the grid.
     */
    public function testPlanetBackgroundImagesExist()
    {
        for ($i = 1; $i <= 7; $i++) {
            $path = public_path("images/planet-{$i}-bg.png");
            $this->assertFileExists($path, "Planet background image planet-{$i}-bg.png is missing from public/images/.");
        }
    }

    /**
     * The sprite-grid.png must exist — it contains all grid/building
     * textures used by the Surface PixiJS renderer.
     */
    public function testSpriteGridImageExists()
    {
        $this->assertFileExists(
            public_path('images/sprite-grid.png'),
            'sprite-grid.png is missing from public/images/.'
        );
    }

    /**
     * The planet API must return a valid resource_id (1–7) so the
     * Surface component can construct the background texture URL.
     */
    public function testPlanetApiReturnsValidResourceId()
    {
        $user = $this->createStartedUser();
        $this->actingAs($user);

        $response = $this->getJson('/api/planet')
            ->assertStatus(200);

        $resourceId = $response->json('resource_id');
        $this->assertNotNull($resourceId, 'Planet API must return a resource_id.');
        $this->assertGreaterThanOrEqual(1, $resourceId, 'resource_id must be >= 1.');
        $this->assertLessThanOrEqual(7, $resourceId, 'resource_id must be <= 7.');
    }

    /**
     * The compiled JavaScript bundle must contain the Surface component
     * with the new viewport wrapper and direct API fallback.
     */
    public function testCompiledJsContainsSurfaceComponent()
    {
        $appJs = public_path('js/app.js');
        $this->assertFileExists($appJs, 'public/js/app.js must exist.');

        $content = file_get_contents($appJs);
        $this->assertStringContainsString('surface-viewport', $content, 'app.js must contain the surface-viewport wrapper.');
        $this->assertStringContainsString('fetchPlanetDirect', $content, 'app.js must contain the direct API fallback.');
        $this->assertStringContainsString('surface-loading', $content, 'app.js must contain the loading indicator.');
    }
}

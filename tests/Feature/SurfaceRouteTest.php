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
}

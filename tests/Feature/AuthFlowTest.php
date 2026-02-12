<?php

namespace Tests\Feature;

use App\Models\Planet;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\SettingsTableSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('installed.lock'))) {
            file_put_contents(storage_path('installed.lock'), 'installed');
        }

        $this->seed(SettingsTableSeeder::class);
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('installed.lock'));
        parent::tearDown();
    }

    protected function createStartedUser(string $password = 'secret123'): User
    {
        $user = User::factory()->create([
            'password' => bcrypt($password),
            'is_enabled' => true,
            'email_verified_at' => Carbon::now(),
        ]);
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
     * Login page loads correctly.
     */
    public function testLoginPageLoads()
    {
        $this->get('/login')
            ->assertStatus(200)
            ->assertSee('csrf-token');
    }

    /**
     * Login POST with valid credentials redirects to home (not blank).
     */
    public function testLoginPostRedirectsToHome()
    {
        $user = $this->createStartedUser('password123');

        $response = $this->post('/login', [
            'username_or_email' => $user->username,
            'password' => 'password123',
        ]);

        $response->assertRedirect('/');
    }

    /**
     * Login POST with valid email redirects to home.
     */
    public function testLoginWithEmailRedirects()
    {
        $user = $this->createStartedUser('password123');

        $response = $this->post('/login', [
            'username_or_email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertRedirect('/');
    }

    /**
     * Login POST with bad credentials returns validation error.
     */
    public function testLoginWithBadCredentialsFails()
    {
        $user = $this->createStartedUser('password123');

        $response = $this->from('/login')->post('/login', [
            'username_or_email' => $user->username,
            'password' => 'wrong_password',
        ]);

        // Should redirect back to login with errors.
        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('username_or_email');
    }

    /**
     * After successful login, GET / returns 200 (not blank/500).
     */
    public function testHomePageLoadsAfterLogin()
    {
        $user = $this->createStartedUser();
        $this->actingAs($user);

        $this->get('/')
            ->assertStatus(200);
    }

    /**
     * Logout invalidates the session and redirects to login.
     */
    public function testLogoutInvalidatesSession()
    {
        $user = $this->createStartedUser();
        $this->actingAs($user);

        // Logout via GET (matches Route::match(['get', 'post']))
        $response = $this->get('/logout');
        $response->assertRedirect('/login');

        // After logout, accessing protected route redirects.
        $this->get('/')
            ->assertRedirect();
    }

    /**
     * Logout via POST also works.
     */
    public function testLogoutViaPost()
    {
        $user = $this->createStartedUser();
        $this->actingAs($user);

        $response = $this->post('/logout');
        $response->assertRedirect('/login');
    }

    /**
     * Authenticated user accessing /login is redirected to home.
     */
    public function testAuthenticatedUserRedirectedFromLogin()
    {
        $user = $this->createStartedUser();
        $this->actingAs($user);

        $this->get('/login')
            ->assertRedirect('/');
    }

    /**
     * CSRF token is present in the login form.
     */
    public function testCsrfTokenPresentInLoginForm()
    {
        $response = $this->get('/login')
            ->assertStatus(200);

        // The base layout includes <meta name="csrf-token" content="...">
        $response->assertSee('name="csrf-token"', false);
        // The login form includes {{ csrf_field() }}
        $response->assertSee('name="_token"', false);
    }
}

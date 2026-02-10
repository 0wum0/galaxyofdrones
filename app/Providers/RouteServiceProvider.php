<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            // API routes use the 'web' middleware group so that session-based
            // authentication works for same-origin AJAX calls from the Vue
            // frontend.  Previously these used the 'api' group which had no
            // session/cookie middleware, causing Passport's token-based auth
            // to be the only option — fragile on shared hosting where Passport
            // keys may be missing (→ 500) or the laravel_token cookie fails
            // to be created (→ 401 infinite reload).
            //
            // Rate limiting is added on top via 'throttle:api'.
            Route::prefix('api')
                ->as('api_')
                ->middleware(['web', 'throttle:api'])
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            // Installer routes use a lightweight middleware group that
            // does NOT include CSRF verification, Passport, or CheckInstalled.
            // This prevents 419 errors and Passport exceptions during install.
            Route::middleware('installer')
                ->namespace($this->namespace)
                ->group(base_path('routes/installer.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by(optional($request->user())->id ?: $request->ip());
        });
    }
}

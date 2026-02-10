<?php

use App\Http\Controllers\CronController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Admin\LogController as AdminLogController;
use App\Http\Controllers\Admin\StarmapController as AdminStarmapController;
use App\Http\Controllers\Web\ForgotPasswordController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\RegisterController;
use App\Http\Controllers\Web\ResetPasswordController;
use App\Http\Controllers\Web\StartController;
use App\Http\Controllers\Web\VerificationController;

/*
|--------------------------------------------------------------------------
| Installer Routes → moved to routes/installer.php
| (uses 'installer' middleware group, see RouteServiceProvider)
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Cron HTTP Endpoint (token-protected)
|--------------------------------------------------------------------------
*/

Route::get('/cron/tick', [CronController::class, 'tick'])->name('cron.tick');

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/

Route::group([
    'prefix' => 'admin',
    'as' => 'admin.',
    'middleware' => ['web', 'auth', 'admin'],
], function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
    Route::put('/users/{user}/toggle-admin', [AdminUserController::class, 'toggleAdmin'])->name('users.toggle_admin');
    Route::put('/users/{user}/toggle-enabled', [AdminUserController::class, 'toggleEnabled'])->name('users.toggle_enabled');
    Route::get('/settings', [AdminSettingController::class, 'index'])->name('settings.index');
    Route::post('/settings', [AdminSettingController::class, 'store'])->name('settings.store');
    Route::put('/settings/{gameSetting}', [AdminSettingController::class, 'update'])->name('settings.update');
    Route::delete('/settings/{gameSetting}', [AdminSettingController::class, 'destroy'])->name('settings.destroy');
    Route::get('/logs', [AdminLogController::class, 'index'])->name('logs.index');
    Route::get('/starmap', [AdminStarmapController::class, 'index'])->name('starmap.index');
    Route::post('/starmap/generate', [AdminStarmapController::class, 'generate'])->name('starmap.generate');
    Route::post('/starmap/expand', [AdminStarmapController::class, 'expand'])->name('starmap.expand');
});

/*
|--------------------------------------------------------------------------
| Game Routes
|--------------------------------------------------------------------------
*/

Route::group([
    'prefix' => 'start',
    'middleware' => ['log.csrf', 'no.cache'],
], function () {
    Route::post('/', [StartController::class, 'store'])
        ->name('start_store');

    Route::get('/', [StartController::class, 'index'])
        ->name('start');
});

Route::group([
    'prefix' => 'register',
    'middleware' => ['no.cache'],
], function () {
    Route::get('/', [RegisterController::class, 'showRegistrationForm'])
        ->name('register');

    Route::post('/', [RegisterController::class, 'register'])
        ->name('register');
});

Route::group([
    'prefix' => 'password',
    'middleware' => ['no.cache'],
], function () {
    Route::get('reset', [ForgotPasswordController::class, 'showLinkRequestForm'])
        ->name('password.request');

    Route::post('email', [ForgotPasswordController::class, 'sendResetLinkEmail'])
        ->name('password.email');

    Route::get('password/reset/{token}', [ResetPasswordController::class, 'showResetForm'])
        ->name('password.reset');

    Route::post('reset', [ResetPasswordController::class, 'reset'])
        ->name('password.update');
});

Route::group([
    'prefix' => 'email',
], function () {
    Route::get('verify', [VerificationController::class, 'show'])
        ->name('verification.notice');

    Route::get('verify/{id}/{hash}', [VerificationController::class, 'verify'])
        ->name('verification.verify');

    Route::post('resend', [VerificationController::class, 'resend'])
        ->name('verification.resend');

    Route::post('update', [VerificationController::class, 'update'])
        ->name('verification.update');
});

Route::group([
    'prefix' => 'login',
    'middleware' => ['no.cache'],
], function () {
    Route::get('/', [LoginController::class, 'showLoginForm'])
        ->name('login');

    Route::post('/', [LoginController::class, 'login'])
        ->name('login');
});

Route::get('logout', [LoginController::class, 'logout'])
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Debug: CSRF / Session diagnostic endpoints (auth + signed URL required)
|--------------------------------------------------------------------------
| Usage:  php artisan tinker  →  URL::signedRoute('debug.csrf')
|         php artisan tinker  →  URL::signedRoute('debug.session')
| Then open the signed URL while logged in. Safe on production because:
|   1) requires authentication
|   2) requires a valid signed URL (unguessable)
| Remove these routes once session issues are resolved.
*/
Route::get('debug/csrf', function (\Illuminate\Http\Request $request) {
    $user = $request->user();
    $sessionToken = $request->hasSession() ? $request->session()->token() : null;

    // Collect cookie names present in the request
    $cookieNames = [];
    foreach ($request->cookies->all() as $name => $value) {
        $cookieNames[$name] = is_string($value) ? strlen($value) : 'non-string';
    }

    // Collect relevant headers
    $relevantHeaders = [];
    foreach (['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-host', 'x-forwarded-port', 'x-csrf-token', 'x-xsrf-token', 'referer', 'host', 'origin'] as $h) {
        $val = $request->header($h);
        $relevantHeaders[$h] = $val ?: 'not-present';
    }

    return response()->json([
        // App config
        'app_url'                => config('app.url'),
        'app_env'                => config('app.env'),

        // Request detection
        'request_host'           => $request->getHost(),
        'request_scheme'         => $request->getScheme(),
        'request_is_secure'      => $request->isSecure(),
        'request_ip'             => $request->ip(),

        // Session config
        'session_driver'         => config('session.driver'),
        'session_cookie_name'    => config('session.cookie'),
        'session_domain'         => config('session.domain'),
        'session_secure'         => config('session.secure'),
        'session_samesite'       => config('session.same_site'),
        'session_lifetime'       => config('session.lifetime'),
        'session_path'           => config('session.path'),

        // Session state
        'session_id'             => $request->hasSession() ? $request->session()->getId() : 'NO_SESSION',
        'csrf_token_func'        => csrf_token() ? 'present(' . strlen(csrf_token()) . ')' : 'MISSING',
        'session_token'          => $sessionToken ? 'present(' . strlen($sessionToken) . ')' : 'MISSING',
        'tokens_match'           => csrf_token() === $sessionToken,

        // Cookies in request
        'cookies_present'        => $cookieNames,

        // Headers
        'relevant_headers'       => $relevantHeaders,

        // Auth
        'user_id'                => $user?->id,

        // System
        'php_version'            => PHP_VERSION,
        'laravel_version'        => app()->version(),
        'session_save_path'      => session_save_path(),
    ]);
})->middleware(['auth', 'signed'])->name('debug.csrf');

Route::get('debug/session', function (\Illuminate\Http\Request $request) {
    $user = $request->user();
    return response()->json([
        'session_id'        => $request->session()->getId(),
        'csrf_token_present' => (bool) $request->session()->token(),
        'cookie_name'       => config('session.cookie'),
        'session_domain'    => config('session.domain'),
        'session_secure'    => config('session.secure'),
        'session_samesite'  => config('session.same_site'),
        'session_driver'    => config('session.driver'),
        'session_lifetime'  => config('session.lifetime'),
        'app_url'           => config('app.url'),
        'app_env'           => config('app.env'),
        'request_secure'    => $request->isSecure(),
        'request_host'      => $request->getHost(),
        'request_scheme'    => $request->getScheme(),
        'user_id'           => $user?->id,
        'user_started'      => $user ? $user->isStarted() : null,
        'php_version'       => PHP_VERSION,
        'session_save_path' => session_save_path(),
    ]);
})->middleware(['auth', 'signed'])->name('debug.session');

Route::get('/{vue?}', [HomeController::class, 'index'])
    ->name('home')
    ->where('vue', 'starmap');

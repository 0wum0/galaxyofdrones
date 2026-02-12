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
    'middleware' => ['no.cache'],
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
        ->name('register_store');
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
        ->name('login_store');
});

Route::match(['get', 'post'], 'logout', [LoginController::class, 'logout'])
    ->name('logout');

Route::get('/{vue?}', [HomeController::class, 'index'])
    ->name('home')
    ->middleware('no.cache')
    ->where('vue', 'starmap');

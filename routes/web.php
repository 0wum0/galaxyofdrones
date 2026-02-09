<?php

use App\Http\Controllers\InstallController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Admin\LogController as AdminLogController;
use App\Http\Controllers\Web\ForgotPasswordController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\RegisterController;
use App\Http\Controllers\Web\ResetPasswordController;
use App\Http\Controllers\Web\StartController;
use App\Http\Controllers\Web\VerificationController;

/*
|--------------------------------------------------------------------------
| Installer Routes (only accessible when not installed)
|--------------------------------------------------------------------------
*/

Route::group([
    'prefix' => 'install',
    'as' => 'install.',
], function () {
    Route::get('/', [InstallController::class, 'index'])->name('index');
    Route::get('/database', [InstallController::class, 'database'])->name('database');
    Route::post('/test-database', [InstallController::class, 'testDatabase'])->name('test_database');
    Route::post('/environment', [InstallController::class, 'environment'])->name('environment');
    Route::get('/migrate', [InstallController::class, 'migrate'])->name('migrate');
    Route::get('/admin', [InstallController::class, 'admin'])->name('admin');
    Route::post('/admin', [InstallController::class, 'createAdmin'])->name('create_admin');
    Route::get('/complete', [InstallController::class, 'complete'])->name('complete');
});

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
});

/*
|--------------------------------------------------------------------------
| Game Routes
|--------------------------------------------------------------------------
*/

Route::group([
    'prefix' => 'start',
], function () {
    Route::post('/', [StartController::class, 'store'])
        ->name('start_store');

    Route::get('/', [StartController::class, 'index'])
        ->name('start');
});

Route::group([
    'prefix' => 'register',
], function () {
    Route::get('/', [RegisterController::class, 'showRegistrationForm'])
        ->name('register');

    Route::post('/', [RegisterController::class, 'register'])
        ->name('register');
});

Route::group([
    'prefix' => 'password',
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
], function () {
    Route::get('/', [LoginController::class, 'showLoginForm'])
        ->name('login');

    Route::post('/', [LoginController::class, 'login'])
        ->name('login');
});

Route::get('logout', [LoginController::class, 'logout'])
    ->name('logout');

Route::get('/{vue?}', [HomeController::class, 'index'])
    ->name('home')
    ->where('vue', 'starmap');

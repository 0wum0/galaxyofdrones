<?php

use App\Http\Controllers\InstallController;

/*
|--------------------------------------------------------------------------
| Installer Routes
|--------------------------------------------------------------------------
|
| These routes use the 'installer' middleware group which provides sessions
| (for old() / withErrors()) but does NOT include:
|   - VerifyCsrfToken  (installer is protected by its own lock/token logic)
|   - Passport          (may not be installed yet)
|   - CheckInstalled    (installer handles this itself)
|
| This separation is the key fix for the 419 / redirect-loop bug:
| when the installer writes a new .env with a new APP_KEY, the session
| cookie (encrypted with the old key) becomes unreadable. With a
| lightweight middleware stack and file-based state tracking, the
| installer flow survives APP_KEY changes reliably.
|
*/

Route::group([
    'prefix' => 'install',
    'as' => 'install.',
], function () {
    Route::get('/', [InstallController::class, 'index'])->name('index');
    Route::get('/database', [InstallController::class, 'database'])->name('database');
    Route::post('/test-database', [InstallController::class, 'testDatabase'])->name('test_database');
    Route::post('/database', [InstallController::class, 'testDatabase'])->name('database_post');
    Route::post('/environment', [InstallController::class, 'environment'])->name('environment');
    Route::get('/migrate', [InstallController::class, 'migrate'])->name('migrate');
    Route::get('/starmap', [InstallController::class, 'starmap'])->name('starmap');
    Route::post('/starmap', [InstallController::class, 'generateStarmap'])->name('generate_starmap');
    Route::get('/admin', [InstallController::class, 'admin'])->name('admin');
    Route::post('/admin', [InstallController::class, 'createAdmin'])->name('create_admin');
    Route::get('/complete', [InstallController::class, 'complete'])->name('complete');
    Route::post('/update', [InstallController::class, 'runUpdate'])->name('run_update');
});

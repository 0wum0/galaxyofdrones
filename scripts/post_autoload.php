<?php

/**
 * Deployment-safe post-autoload-dump script.
 *
 * Replaces the direct "@php artisan package:discover --ansi" call in composer.json.
 * On first deploy (before the web installer has run), .env and APP_KEY don't exist yet,
 * which causes Laravel to crash during boot. This script guards against that by only
 * running package:discover when the environment is fully set up.
 *
 * Safety guarantees:
 *   - If .env is missing the script prints a notice and exits cleanly (exit 0).
 *   - All Artisan calls are wrapped in try/catch; any failure prints a message
 *     and exits with code 0 so "composer install" never fails at this stage.
 *   - No dependency on storage/installed.lock or any other untracked state.
 *   - The script NEVER exits with code 255 (PHP fatal) under any circumstances.
 */

// ---------------------------------------------------------------------------
// Wrap EVERYTHING in a top-level try/catch so that no uncaught exception or
// error can ever bubble up as exit-code 255 to Composer / the deploy runner.
// ---------------------------------------------------------------------------
try {

    // -----------------------------------------------------------------------
    // Determine the project root (one directory above scripts/)
    // -----------------------------------------------------------------------
    $projectRoot = dirname(__DIR__);

    $envFile = $projectRoot . DIRECTORY_SEPARATOR . '.env';

    // -----------------------------------------------------------------------
    // Guard 1: .env must exist
    // On a clean first deploy the .env has not been created yet (the web
    // installer or a manual "cp .env.example .env && php artisan key:generate"
    // creates it). Without .env, Laravel's bootstrap will crash, so we bail
    // out early.
    // -----------------------------------------------------------------------
    if (! file_exists($envFile)) {
        echo "\033[33m⏭  Skipping package:discover – .env not found (first deploy, installer pending).\033[0m" . PHP_EOL;
        exit(0);
    }

    // -----------------------------------------------------------------------
    // Guard 2: artisan file must exist
    // -----------------------------------------------------------------------
    $artisan = $projectRoot . DIRECTORY_SEPARATOR . 'artisan';

    if (! file_exists($artisan)) {
        echo "\033[33m⏭  Skipping package:discover – artisan file not found.\033[0m" . PHP_EOL;
        exit(0);
    }

    // -----------------------------------------------------------------------
    // .env exists – run package:discover
    // -----------------------------------------------------------------------
    echo "\033[32m✔  Running package:discover (.env found, environment ready)…\033[0m" . PHP_EOL;

    $phpBinary = PHP_BINARY ?: 'php';
    $command   = escapeshellarg($phpBinary) . ' ' . escapeshellarg($artisan) . ' package:discover --ansi 2>&1';

    $output   = [];
    $exitCode = 0;

    exec($command, $output, $exitCode);

    // Always print the output so the developer can see what happened.
    echo implode(PHP_EOL, $output) . PHP_EOL;

    if ($exitCode !== 0) {
        // Log the failure but do NOT propagate the error to Composer.
        // This prevents "composer install" from failing on edge cases
        // (e.g. corrupted cache, missing APP_KEY, temporary file-lock issues).
        echo "\033[31m⚠  package:discover exited with code {$exitCode} – ignoring to keep deploy safe.\033[0m" . PHP_EOL;
        echo "\033[31m   Run 'php artisan package:discover --ansi' manually to debug.\033[0m" . PHP_EOL;
    }

} catch (\Throwable $e) {
    // -----------------------------------------------------------------------
    // Catch absolutely everything (\Error, \Exception, …) so that a PHP fatal
    // can never surface as exit-code 255 to the deploy pipeline.
    // -----------------------------------------------------------------------
    echo "\033[31m⚠  post_autoload.php caught an error: " . $e->getMessage() . "\033[0m" . PHP_EOL;
    echo "\033[31m   File: " . $e->getFile() . ':' . $e->getLine() . "\033[0m" . PHP_EOL;
    echo "\033[31m   Deploy continues – run 'php artisan package:discover' manually if needed.\033[0m" . PHP_EOL;
}

// ---------------------------------------------------------------------------
// ALWAYS exit 0 – this script must never break "composer install".
// ---------------------------------------------------------------------------
exit(0);

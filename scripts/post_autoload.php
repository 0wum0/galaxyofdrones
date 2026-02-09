<?php

/**
 * Deployment-safe post-autoload-dump script.
 *
 * Replaces the direct "@php artisan package:discover --ansi" call in composer.json.
 * On first deploy (before the web installer has run), .env and APP_KEY don't exist yet,
 * which causes Laravel to crash during boot. This script guards against that by only
 * running package:discover when the environment is fully set up.
 *
 * Conditions checked:
 *   1. .env file exists               – created by the web installer
 *   2. storage/installed.lock exists   – created at the end of the web installer
 *
 * If either condition is not met, the script exits cleanly (exit 0) so that
 * "composer install" never fails because of a missing environment.
 */

// ---------------------------------------------------------------------------
// Determine the project root (one directory above scripts/)
// ---------------------------------------------------------------------------
$projectRoot = dirname(__DIR__);

$envFile       = $projectRoot . DIRECTORY_SEPARATOR . '.env';
$installedLock = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'installed.lock';

// ---------------------------------------------------------------------------
// Guard: .env must exist
// ---------------------------------------------------------------------------
if (! file_exists($envFile)) {
    echo "\033[33m⏭  Skipping package:discover – .env not found (first deploy, installer pending).\033[0m" . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------------------
// Guard: storage/installed.lock must exist
// ---------------------------------------------------------------------------
if (! file_exists($installedLock)) {
    echo "\033[33m⏭  Skipping package:discover – storage/installed.lock not found (installer not completed yet).\033[0m" . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------------------
// Both guards passed – run package:discover
// ---------------------------------------------------------------------------
echo "\033[32m✔  Running package:discover (environment ready)…\033[0m" . PHP_EOL;

$phpBinary = PHP_BINARY ?: 'php';
$artisan   = $projectRoot . DIRECTORY_SEPARATOR . 'artisan';
$command   = escapeshellarg($phpBinary) . ' ' . escapeshellarg($artisan) . ' package:discover --ansi 2>&1';

$output    = [];
$exitCode  = 0;

exec($command, $output, $exitCode);

// Always print the output so the developer can see what happened
echo implode(PHP_EOL, $output) . PHP_EOL;

if ($exitCode !== 0) {
    // Log the failure but do NOT propagate the error to composer.
    // This prevents "composer install" from failing on edge cases
    // (e.g. corrupted cache, temporary file-lock issues).
    echo "\033[31m⚠  package:discover exited with code {$exitCode} – ignoring to keep deploy safe.\033[0m" . PHP_EOL;
    echo "\033[31m   Run 'php artisan package:discover --ansi' manually to debug.\033[0m" . PHP_EOL;
}

// Always exit 0 so that composer never fails at this stage.
exit(0);

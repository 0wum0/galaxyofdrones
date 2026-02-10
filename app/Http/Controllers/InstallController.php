<?php

namespace App\Http\Controllers;

use App\Models\GameSetting;
use App\Models\Planet;
use App\Models\Star;
use App\Models\User;
use App\Support\InstallerState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InstallController extends Controller
{
    protected InstallerState $state;

    public function __construct()
    {
        $this->state = new InstallerState();
    }

    // ─── INSTALLER STEPS ─────────────────────────────────

    /**
     * Step 1: System requirements check.
     */
    public function index(Request $request)
    {
        if ($this->isInstalled() && !$this->isUnlocked($request)) {
            return redirect('/')->with('message', 'Application is already installed.');
        }

        // If installed, show updater mode
        if ($this->isInstalled()) {
            return $this->updater($request);
        }

        $requirements = $this->checkRequirements();
        $permissions = $this->checkPermissions();
        $allPassed = !in_array(false, array_column($requirements, 'passed'))
                  && !in_array(false, array_column($permissions, 'passed'));

        $this->installerLog('Step 1: Requirements check', [
            'all_passed' => $allPassed,
        ]);

        return view('install.requirements', compact('requirements', 'permissions', 'allPassed'));
    }

    /**
     * Step 2: Database configuration form.
     */
    public function database(Request $request)
    {
        if ($this->isInstalled() && !$this->isUnlocked($request)) {
            return redirect('/');
        }

        // Load defaults from installer state first (survives APP_KEY change),
        // then fall back to .env values, then to sensible defaults.
        $stateData = $this->state->all();
        $defaults = [
            'db_host'     => $stateData['db_host'] ?? trimQuotes(env('DB_HOST', 'localhost')),
            'db_port'     => $stateData['db_port'] ?? trimQuotes(env('DB_PORT', '3306')),
            'db_database' => $stateData['db_database'] ?? trimQuotes(env('DB_DATABASE', '')),
            'db_username' => $stateData['db_username'] ?? trimQuotes(env('DB_USERNAME', '')),
            'db_password' => $stateData['db_password'] ?? trimQuotes(env('DB_PASSWORD', '')),
        ];

        return view('install.database', compact('defaults'));
    }

    /**
     * Step 2b: Test database connection (AJAX or form POST).
     */
    public function testDatabase(Request $request)
    {
        $wantsJson = $request->expectsJson() || $request->ajax();

        if ($this->isInstalled() && !$this->isUnlocked($request)) {
            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => 'Already installed.']);
            }
            return redirect('/');
        }

        $validator = Validator::make($request->all(), [
            'db_host' => 'required|string',
            'db_port' => 'required|numeric',
            'db_database' => 'required|string',
            'db_username' => 'required|string',
            'db_password' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            $this->installerLog('DB test: validation failed', $validator->errors()->toArray());

            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()]);
            }
            return redirect()->route('install.database')
                ->withErrors($validator)
                ->withInput();
        }

        $host     = trimQuotes($request->db_host);
        $port     = trimQuotes($request->db_port);
        $database = trimQuotes($request->db_database);
        $username = trimQuotes($request->db_username);
        $password = trimQuotes($request->db_password ?? '');

        try {
            $connection = new \PDO(
                "mysql:host={$host};port={$port};dbname={$database}",
                $username,
                $password,
                [\PDO::ATTR_TIMEOUT => 5]
            );
            $connection = null;
        } catch (\PDOException $e) {
            $errorMsg = 'Connection failed: ' . $this->sanitizeError($e->getMessage());
            $this->installerLog('DB test: connection failed', ['error' => $errorMsg]);

            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => $errorMsg]);
            }
            return redirect()->route('install.database')
                ->withErrors(['db_host' => $errorMsg])
                ->withInput();
        }

        // If AJAX test only, return success JSON
        if ($wantsJson) {
            return response()->json(['success' => true, 'message' => 'Database connection successful!']);
        }

        $this->installerLog('DB test: connection successful');

        // Persist DB credentials in file-based state (survives APP_KEY change)
        $this->state->set([
            'db_host' => $host,
            'db_port' => $port,
            'db_database' => $database,
            'db_username' => $username,
            'db_password' => $password,
        ]);

        // Proceed to write .env
        return $this->writeEnvironment($request, $host, $port, $database, $username, $password);
    }

    /**
     * Step 3: Save database config, create .env, generate key.
     *
     * Called internally from testDatabase() on success.
     * Also available as a POST endpoint for retry scenarios.
     */
    public function environment(Request $request)
    {
        if ($this->isInstalled() && !$this->isUnlocked($request)) {
            return redirect('/');
        }

        $validator = Validator::make($request->all(), [
            'db_host' => 'required|string',
            'db_port' => 'required|numeric',
            'db_database' => 'required|string',
            'db_username' => 'required|string',
            'db_password' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return redirect()->route('install.database')
                ->withErrors($validator)
                ->withInput();
        }

        $host     = trimQuotes($request->db_host);
        $port     = trimQuotes($request->db_port);
        $database = trimQuotes($request->db_database);
        $username = trimQuotes($request->db_username);
        $password = trimQuotes($request->db_password ?? '');

        // Persist in state
        $this->state->set([
            'db_host' => $host,
            'db_port' => $port,
            'db_database' => $database,
            'db_username' => $username,
            'db_password' => $password,
        ]);

        return $this->writeEnvironment($request, $host, $port, $database, $username, $password);
    }

    /**
     * Internal: Write the .env file and redirect to migrate.
     */
    protected function writeEnvironment(Request $request, string $host, string $port, string $database, string $username, string $password)
    {
        // Verify DB connection one more time
        try {
            new \PDO(
                "mysql:host={$host};port={$port};dbname={$database}",
                $username,
                $password,
                [\PDO::ATTR_TIMEOUT => 5]
            );
        } catch (\PDOException $e) {
            $this->installerLog('Environment: DB verification failed', ['error' => $e->getMessage()]);
            return redirect()->route('install.database')
                ->withErrors(['db_host' => 'Database connection failed: ' . $this->sanitizeError($e->getMessage())])
                ->withInput();
        }

        // Preserve existing APP_KEY if present (prevents session invalidation on re-run)
        $existingKey = $this->getExistingAppKey();
        $appKey = $existingKey ?: ('base64:' . base64_encode(random_bytes(32)));

        $appUrl = $request->getSchemeAndHttpHost();
        $cronToken = Str::random(32);
        $installToken = Str::random(32);

        $envContent = $this->buildEnvContent([
            'APP_NAME' => 'Galaxy of Drones Online',
            'APP_ENV' => 'production',
            'APP_KEY' => $appKey,
            'APP_DEBUG' => 'false',
            'APP_URL' => $appUrl,
            'APP_TIMEZONE' => 'UTC',
            'APP_SPEED' => '1',
            'LOG_CHANNEL' => 'single',
            'LOG_LEVEL' => 'error',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $username,
            'DB_PASSWORD' => $password,
            'BROADCAST_DRIVER' => 'log',
            'CACHE_DRIVER' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'file',
            'SESSION_LIFETIME' => '120',
            'SESSION_SECURE_COOKIE' => 'null',
            'SESSION_SAME_SITE' => 'lax',
            'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => 'localhost',
            'MAIL_PORT' => '587',
            'MAIL_USERNAME' => 'null',
            'MAIL_PASSWORD' => 'null',
            'MAIL_ENCRYPTION' => 'null',
            'MAIL_FROM_ADDRESS' => 'noreply@' . $request->getHost(),
            'MAIL_FROM_NAME' => '${APP_NAME}',
            'CRON_TOKEN' => $cronToken,
            'INSTALL_TOKEN' => $installToken,
        ]);

        $envPath = base_path('.env');
        file_put_contents($envPath, $envContent);
        @chmod($envPath, 0600);

        $this->installerLog('Environment: .env written', [
            'app_url' => $appUrl,
            'key_reused' => !empty($existingKey),
        ]);

        // Clear caches so new config is picked up
        foreach (['config:clear', 'cache:clear', 'view:clear', 'route:clear'] as $cmd) {
            try {
                Artisan::call($cmd);
            } catch (\Exception $e) {
                // Ignore – caches may not exist yet
            }
        }

        // Mark env step as completed in FILE-BASED state (not session!)
        $this->state->markCompleted('env_written');
        $this->state->set([
            'cron_token' => $cronToken,
            'install_token' => $installToken,
        ]);

        // Redirect to migrate step
        return redirect()->route('install.migrate');
    }

    /**
     * Step 4+5: Run migrations and seeders.
     */
    public function migrate(Request $request)
    {
        if ($this->isInstalled() && !$this->isUnlocked($request)) {
            return redirect('/');
        }

        // Check file-based state (NOT session – sessions break after APP_KEY change)
        if (!$this->state->hasCompleted('env_written')) {
            $this->installerLog('Migrate: env not written yet, redirecting to database');
            return redirect()->route('install.database');
        }

        $results = [];
        $errors = [];

        try {
            // Re-read the .env we just wrote
            try {
                Artisan::call('config:clear');
            } catch (\Exception $e) {
                // Ignore
            }

            // Reload environment from .env file
            try {
                $dotenv = \Dotenv\Dotenv::createMutable(base_path());
                $dotenv->load();
            } catch (\Exception $e) {
                // Ignore
            }

            // Apply the DB config from our state file (most reliable source)
            $dbHost = $this->state->get('db_host', env('DB_HOST'));
            $dbPort = $this->state->get('db_port', env('DB_PORT'));
            $dbDatabase = $this->state->get('db_database', env('DB_DATABASE'));
            $dbUsername = $this->state->get('db_username', env('DB_USERNAME'));
            $dbPassword = $this->state->get('db_password', env('DB_PASSWORD'));

            config([
                'database.connections.mysql.host' => $dbHost,
                'database.connections.mysql.port' => $dbPort,
                'database.connections.mysql.database' => $dbDatabase,
                'database.connections.mysql.username' => $dbUsername,
                'database.connections.mysql.password' => $dbPassword,
            ]);
            DB::purge('mysql');
            DB::reconnect('mysql');

            $results[] = 'Configuration loaded.';

            Artisan::call('migrate', ['--force' => true]);
            $results[] = 'Database migrations completed.';
            $migrationOutput = trim(Artisan::output());
            if ($migrationOutput) {
                $results[] = $migrationOutput;
            }

            Artisan::call('db:seed', ['--force' => true]);
            $results[] = 'Database seeding completed.';
            $seedOutput = trim(Artisan::output());
            if ($seedOutput) {
                $results[] = $seedOutput;
            }

            try {
                Artisan::call('passport:install', ['--force' => true]);
                $results[] = 'Passport keys installed.';
            } catch (\Exception $e) {
                $results[] = 'Passport setup skipped: ' . $this->sanitizeError($e->getMessage());
            }

            try {
                Artisan::call('storage:link');
                $results[] = 'Storage link created.';
            } catch (\Exception $e) {
                $results[] = 'Storage link skipped (may already exist).';
            }

            // Mark step as completed in file-based state
            $this->state->markCompleted('migrated');
            $this->installerLog('Migrate: completed successfully');

        } catch (\Exception $e) {
            $errors[] = 'Migration error: ' . $this->sanitizeError($e->getMessage());
            $errors[] = 'Check storage/logs/laravel.log for details.';
            $this->installerLog('Migrate: FAILED', ['error' => $e->getMessage()]);

            return view('install.migrate', compact('results', 'errors'));
        }

        return view('install.migrate', compact('results', 'errors'));
    }

    /**
     * Step 6: StarMap generation.
     */
    public function starmap(Request $request)
    {
        if ($this->isInstalled() && !$this->isUnlocked($request)) {
            return redirect('/');
        }

        if (!$this->state->hasCompleted('migrated')) {
            return redirect()->route('install.database');
        }

        $this->reconnectDatabase();

        $starCount = Star::count();
        $planetCount = Planet::count();
        $starmapExists = $starCount > 0 && $planetCount > 0;
        $starterCount = 0;

        if ($starmapExists) {
            try {
                $starterCount = Planet::starter()->count();
            } catch (\Exception $e) {
                $starterCount = 0;
            }
        }

        return view('install.starmap', compact('starmapExists', 'starCount', 'planetCount', 'starterCount'));
    }

    /**
     * Step 6b: Generate the starmap.
     */
    public function generateStarmap(Request $request)
    {
        if ($this->isInstalled() && !$this->isUnlocked($request)) {
            return redirect('/');
        }

        $this->reconnectDatabase();

        $results = [];
        $errors = [];

        try {
            set_time_limit(300); // Allow 5 minutes

            $clear = $request->input('clear', false);
            $stars = (int) $request->input('stars', 2000);

            // Clamp values for shared hosting safety
            $stars = max(100, min($stars, 5000));

            $args = [
                '--stars' => $stars,
                '--planets-per-star' => 3,
                '--shared-hosting' => true,
            ];

            if ($clear) {
                $args['--clear'] = true;
            }

            Artisan::call('game:generate-starmap', $args);
            $output = trim(Artisan::output());
            $results[] = $output;

            $starCount = Star::count();
            $planetCount = Planet::count();
            $starterCount = Planet::starter()->count();

            $results[] = "Stars: {$starCount}, Planets: {$planetCount}, Starter slots: {$starterCount}";
            $this->installerLog('StarMap: generated', ['stars' => $starCount, 'planets' => $planetCount]);

        } catch (\Exception $e) {
            $errors[] = 'StarMap generation error: ' . $this->sanitizeError($e->getMessage());
            $this->installerLog('StarMap: FAILED', ['error' => $e->getMessage()]);
        }

        $this->state->markCompleted('starmap_done');

        $starmapExists = Star::count() > 0;
        $starCount = Star::count();
        $planetCount = Planet::count();
        $starterCount = 0;
        try {
            $starterCount = Planet::starter()->count();
        } catch (\Exception $e) {}

        return view('install.starmap', compact('starmapExists', 'starCount', 'planetCount', 'starterCount', 'results', 'errors'));
    }

    /**
     * Step 7: Admin user creation form.
     */
    public function admin(Request $request)
    {
        if ($this->isInstalled() && !$this->isUnlocked($request)) {
            return redirect('/');
        }

        if (!$this->state->hasCompleted('migrated')) {
            return redirect()->route('install.database');
        }

        return view('install.admin');
    }

    /**
     * Step 7b: Create admin user.
     */
    public function createAdmin(Request $request)
    {
        if ($this->isInstalled() && !$this->isUnlocked($request)) {
            return redirect('/');
        }

        try {
            $this->reconnectDatabase();
        } catch (\Exception $e) {
            return redirect()->route('install.admin')
                ->withErrors(['username' => 'Database connection failed: ' . $this->sanitizeError($e->getMessage())])
                ->withInput();
        }

        $validator = Validator::make($request->all(), [
            'username' => 'required|string|min:3|max:20|unique:users,username',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return redirect()->route('install.admin')
                ->withErrors($validator)
                ->withInput();
        }

        try {
            User::create([
                'username' => $request->username,
                'email' => $request->email,
                'email_verified_at' => now(),
                'password' => Hash::make($request->password),
                'is_admin' => true,
                'is_enabled' => true,
            ]);

            $this->state->markCompleted('admin_created');
            $this->installerLog('Admin: user created', ['username' => $request->username]);

            return redirect()->route('install.complete');
        } catch (\Exception $e) {
            $this->installerLog('Admin: FAILED', ['error' => $e->getMessage()]);
            return redirect()->route('install.admin')
                ->withErrors(['username' => 'Error creating admin: ' . $this->sanitizeError($e->getMessage())])
                ->withInput();
        }
    }

    /**
     * Step 8: Installation complete.
     */
    public function complete(Request $request)
    {
        if ($this->isInstalled() && !$this->isUnlocked($request)) {
            return redirect('/');
        }

        if (!$this->state->hasCompleted('admin_created')) {
            return redirect()->route('install.admin');
        }

        // Read tokens from state (file-based, always available)
        $cronToken = $this->state->get('cron_token', '');
        $installToken = $this->state->get('install_token', '');

        // Fallback: read from config/env
        if (empty($cronToken)) {
            $cronToken = trimQuotes(config('app.cron_token') ?: env('CRON_TOKEN', ''));
        }
        if (empty($installToken)) {
            $installToken = trimQuotes(config('app.install_token') ?: env('INSTALL_TOKEN', ''));
        }

        // Create lock file
        $lockFile = storage_path('installed.lock');
        file_put_contents($lockFile, json_encode([
            'installed_at' => now()->toIso8601String(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ]));

        // Remove unlock file if present
        @unlink(storage_path('install.unlock'));

        // Post-install cleanup
        $postInstallErrors = [];

        foreach (['config:clear', 'cache:clear', 'route:clear', 'view:clear'] as $clearCmd) {
            try {
                Artisan::call($clearCmd);
            } catch (\Exception $e) {
                $postInstallErrors[] = $clearCmd . ' – ' . $this->sanitizeError($e->getMessage());
            }
        }

        try {
            Artisan::call('package:discover', ['--ansi' => true]);
        } catch (\Exception $e) {
            $postInstallErrors[] = 'package:discover – ' . $this->sanitizeError($e->getMessage());
        }

        // Do NOT run config:cache or route:cache here – they fail with
        // closure routes and can cause issues on shared hosting.
        // Just clear caches so fresh config is loaded on next request.

        if (!empty($postInstallErrors)) {
            $this->installerLog('Complete: post-install issues', $postInstallErrors);
        }

        // Clean up installer state file
        $this->state->cleanup();

        $this->installerLog('Complete: installation finished successfully');

        return view('install.complete', compact('cronToken', 'installToken'));
    }

    // ─── UPDATER MODE ────────────────────────────────────

    /**
     * Show updater/repair tools (when already installed + unlocked).
     */
    public function updater(Request $request)
    {
        try {
            $this->reconnectDatabase();
        } catch (\Exception $e) {
            return view('install.updater', [
                'stats' => ['stars' => 0, 'planets' => 0, 'users' => 0, 'starter_planets' => 0],
                'starmapGenerated' => false,
                'dbError' => $this->sanitizeError($e->getMessage()),
            ]);
        }

        $stats = [
            'stars' => Star::count(),
            'planets' => Planet::count(),
            'users' => User::count(),
            'starter_planets' => 0,
        ];

        try {
            $stats['starter_planets'] = Planet::starter()->count();
        } catch (\Exception $e) {}

        $starmapGenerated = false;
        try {
            $starmapGenerated = GameSetting::getValue('starmap_generated', false);
        } catch (\Exception $e) {}

        return view('install.updater', compact('stats', 'starmapGenerated'));
    }

    /**
     * Run updater action.
     */
    public function runUpdate(Request $request)
    {
        if (!$this->isInstalled() || !$this->isUnlocked($request)) {
            return redirect()->route('install.index');
        }

        $this->reconnectDatabase();

        $action = $request->input('action');
        $results = [];
        $errors = [];

        $this->installerLog("Updater: running action '{$action}'");

        try {
            switch ($action) {
                case 'migrate':
                    Artisan::call('migrate', ['--force' => true]);
                    $results[] = 'Migrations completed: ' . trim(Artisan::output());
                    break;

                case 'seed':
                    Artisan::call('db:seed', ['--force' => true]);
                    $results[] = 'Seeders completed: ' . trim(Artisan::output());
                    break;

                case 'cache':
                    foreach (['config:clear', 'cache:clear', 'route:clear', 'view:clear'] as $cmd) {
                        Artisan::call($cmd);
                    }
                    $results[] = 'All caches cleared.';
                    break;

                case 'clear_sessions':
                    $sessDir = storage_path('framework/sessions');
                    if (is_dir($sessDir)) {
                        $files = glob($sessDir . '/*');
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                @unlink($file);
                            }
                        }
                    }
                    try {
                        Artisan::call('cache:clear');
                    } catch (\Exception $e) {}
                    $results[] = 'Sessions and cache cleared.';
                    break;

                case 'generate_starmap':
                    set_time_limit(300);
                    $stars = max(100, min((int) $request->input('stars', 2000), 5000));
                    Artisan::call('game:generate-starmap', [
                        '--stars' => $stars,
                        '--planets-per-star' => 3,
                        '--clear' => true,
                        '--shared-hosting' => true,
                    ]);
                    $results[] = trim(Artisan::output());
                    break;

                case 'expand_starmap':
                    set_time_limit(300);
                    $extraStars = max(100, min((int) $request->input('stars', 500), 2000));
                    Artisan::call('game:generate-starmap', [
                        '--stars' => $extraStars,
                        '--planets-per-star' => 3,
                        '--shared-hosting' => true,
                    ]);
                    $results[] = trim(Artisan::output());
                    break;

                case 'passport':
                    Artisan::call('passport:install', ['--force' => true]);
                    $results[] = 'Passport keys reinstalled.';
                    break;

                default:
                    $errors[] = 'Unknown action: ' . $action;
            }
        } catch (\Exception $e) {
            $errors[] = $action . ' failed: ' . $this->sanitizeError($e->getMessage());
            $this->installerLog("Updater: action '{$action}' FAILED", ['error' => $e->getMessage()]);
        }

        return redirect()->route('install.index', $request->only('token'))
            ->with('updater_results', $results)
            ->with('updater_errors', $errors);
    }

    // ─── LOCK / UNLOCK ───────────────────────────────────

    /**
     * Check if the installer is unlocked.
     */
    protected function isUnlocked(Request $request): bool
    {
        if (!$this->isInstalled()) {
            return true;
        }

        // Check unlock file
        if (file_exists(storage_path('install.unlock'))) {
            return true;
        }

        // Check token in query string OR POST data
        $token = $request->input('token', '');
        if (!empty($token)) {
            $installToken = trimQuotes(config('app.install_token') ?: env('INSTALL_TOKEN', ''));
            $cronToken = trimQuotes(config('app.cron_token') ?: env('CRON_TOKEN', ''));

            if (!empty($installToken) && hash_equals($installToken, $token)) {
                return true;
            }
            if (!empty($cronToken) && hash_equals($cronToken, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if application is installed.
     */
    protected function isInstalled(): bool
    {
        return file_exists(storage_path('installed.lock'));
    }

    // ─── HELPERS ─────────────────────────────────────────

    protected function checkRequirements(): array
    {
        $requirements = [];

        $requirements[] = [
            'name' => 'PHP Version >= 8.1',
            'current' => PHP_VERSION,
            'passed' => version_compare(PHP_VERSION, '8.1.0', '>='),
        ];

        $extensions = [
            'pdo' => 'PDO', 'pdo_mysql' => 'PDO MySQL', 'mbstring' => 'Mbstring',
            'openssl' => 'OpenSSL', 'tokenizer' => 'Tokenizer', 'ctype' => 'Ctype',
            'json' => 'JSON', 'fileinfo' => 'Fileinfo', 'xml' => 'XML',
            'curl' => 'cURL', 'dom' => 'DOM', 'bcmath' => 'BCMath',
        ];

        foreach ($extensions as $ext => $label) {
            $requirements[] = [
                'name' => "PHP Extension: {$label}",
                'current' => extension_loaded($ext) ? 'Installed' : 'Missing',
                'passed' => extension_loaded($ext),
            ];
        }

        $requirements[] = [
            'name' => 'PHP Extension: Imagick (optional, for starmap tiles)',
            'current' => extension_loaded('imagick') ? 'Installed' : 'Not installed',
            'passed' => true,
        ];

        return $requirements;
    }

    protected function checkPermissions(): array
    {
        $dirs = [
            storage_path() => 'storage/',
            storage_path('app') => 'storage/app/',
            storage_path('framework') => 'storage/framework/',
            storage_path('framework/cache') => 'storage/framework/cache/',
            storage_path('framework/sessions') => 'storage/framework/sessions/',
            storage_path('framework/views') => 'storage/framework/views/',
            storage_path('logs') => 'storage/logs/',
            base_path('bootstrap/cache') => 'bootstrap/cache/',
        ];

        $permissions = [];

        foreach ($dirs as $path => $label) {
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }

            $writable = is_dir($path) && is_writable($path);
            $permissions[] = [
                'name' => $label,
                'current' => $writable ? 'Writable' : 'Not writable',
                'passed' => $writable,
            ];
        }

        // Session write test
        $sessDir = storage_path('framework/sessions');
        $sessionWritable = false;
        $sessionStatus = 'Not writable';

        if (is_dir($sessDir) && is_writable($sessDir)) {
            $testFile = $sessDir . '/.session_write_test_' . uniqid();
            try {
                if (@file_put_contents($testFile, 'test') !== false) {
                    $sessionWritable = true;
                    $sessionStatus = 'Writable (session files OK)';
                    @unlink($testFile);
                } else {
                    $sessionStatus = 'Directory exists but cannot write files';
                }
            } catch (\Exception $e) {
                $sessionStatus = 'Write test failed: ' . $e->getMessage();
            }
        } elseif (!is_dir($sessDir)) {
            $sessionStatus = 'Directory missing';
        }

        $permissions[] = [
            'name' => 'Sessions writable (file driver)',
            'current' => $sessionStatus,
            'passed' => $sessionWritable,
        ];

        // .env writable check
        $envPath = base_path('.env');
        $envDir = base_path();
        $envWritable = (file_exists($envPath) && is_writable($envPath)) || is_writable($envDir);
        $permissions[] = [
            'name' => '.env file writable',
            'current' => $envWritable ? 'Writable' : 'Not writable',
            'passed' => $envWritable,
        ];

        return $permissions;
    }

    protected function buildEnvContent(array $values): string
    {
        $lines = [];
        $lastGroup = '';

        foreach ($values as $key => $value) {
            $value = trimQuotes($value);

            $group = explode('_', $key)[0];
            if ($lastGroup && $group !== $lastGroup) {
                $lines[] = '';
            }
            $lastGroup = $group;

            if ($value === '' || $value === null) {
                $lines[] = "{$key}=";
                continue;
            }

            if ($key === 'APP_KEY' && str_starts_with($value, 'base64:')) {
                $lines[] = "{$key}={$value}";
                continue;
            }

            $needsQuoting = $value !== ''
                && (str_contains($value, ' ')
                    || str_contains($value, '#')
                    || str_contains($value, '"')
                    || str_contains($value, '$')
                    || str_contains($value, '\\')
                    || str_contains($value, '`')
                    || str_contains($value, '!'));

            if ($needsQuoting) {
                $escaped = str_replace('\\', '\\\\', $value);
                $escaped = str_replace('"', '\\"', $escaped);
                $escaped = str_replace('$', '\\$', $escaped);
                $value = '"' . $escaped . '"';
            }

            $lines[] = "{$key}={$value}";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Try to read the existing APP_KEY from the .env file.
     *
     * Preserving the key across re-installs prevents session invalidation.
     */
    protected function getExistingAppKey(): ?string
    {
        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            return null;
        }

        $content = @file_get_contents($envPath);
        if ($content === false) {
            return null;
        }

        if (preg_match('/^APP_KEY=(.+)$/m', $content, $matches)) {
            $key = trim($matches[1]);
            // Only reuse if it looks like a valid key
            if (!empty($key) && strlen($key) > 10 && str_starts_with($key, 'base64:')) {
                return $key;
            }
        }

        return null;
    }

    protected function sanitizeError(string $message): string
    {
        $message = preg_replace('/password[\'"\s]*[:=][\'"\s]*[^\s\'"]*/i', 'password=***', $message);
        $message = preg_replace('/using password: \w+/i', 'using password: ***', $message);

        return Str::limit($message, 200);
    }

    protected function reconnectDatabase(): void
    {
        try {
            $dotenv = \Dotenv\Dotenv::createMutable(base_path());
            $dotenv->load();
        } catch (\Exception $e) {
            // Ignore if .env not found
        }

        // Prefer state file values (always up-to-date during install)
        $dbHost = $this->state->get('db_host') ?: env('DB_HOST');
        $dbPort = $this->state->get('db_port') ?: env('DB_PORT');
        $dbDatabase = $this->state->get('db_database') ?: env('DB_DATABASE');
        $dbUsername = $this->state->get('db_username') ?: env('DB_USERNAME');
        $dbPassword = $this->state->get('db_password') ?? env('DB_PASSWORD');

        config([
            'database.connections.mysql.host' => $dbHost,
            'database.connections.mysql.port' => $dbPort,
            'database.connections.mysql.database' => $dbDatabase,
            'database.connections.mysql.username' => $dbUsername,
            'database.connections.mysql.password' => $dbPassword,
        ]);

        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    /**
     * Log installer events to a dedicated log file.
     */
    protected function installerLog(string $message, array $context = []): void
    {
        $logPath = storage_path('logs/installer.log');
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        $line = "[{$timestamp}] {$message}{$contextStr}\n";

        @file_put_contents($logPath, $line, FILE_APPEND);
    }
}

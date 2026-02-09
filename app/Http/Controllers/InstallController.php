<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InstallController extends Controller
{
    /**
     * Step 1: System requirements check.
     */
    public function index()
    {
        if ($this->isInstalled()) {
            return redirect('/')->with('message', 'Application is already installed.');
        }

        $requirements = $this->checkRequirements();
        $permissions = $this->checkPermissions();
        $allPassed = ! in_array(false, array_column($requirements, 'passed'))
                  && ! in_array(false, array_column($permissions, 'passed'));

        return view('install.requirements', compact('requirements', 'permissions', 'allPassed'));
    }

    /**
     * Step 2: Database configuration form.
     */
    public function database()
    {
        if ($this->isInstalled()) {
            return redirect('/');
        }

        // Read current .env values as defaults (already stripped by Dotenv,
        // but trimQuotes() catches edge cases when the file was hand-edited).
        $defaults = [
            'db_host'     => trimQuotes(env('DB_HOST', 'localhost')),
            'db_port'     => trimQuotes(env('DB_PORT', '3306')),
            'db_database' => trimQuotes(env('DB_DATABASE', '')),
            'db_username' => trimQuotes(env('DB_USERNAME', '')),
            'db_password' => trimQuotes(env('DB_PASSWORD', '')),
        ];

        return view('install.database', compact('defaults'));
    }

    /**
     * Step 2b: Test database connection.
     */
    public function testDatabase(Request $request)
    {
        if ($this->isInstalled()) {
            return response()->json(['success' => false, 'message' => 'Already installed.']);
        }

        $validator = Validator::make($request->all(), [
            'db_host' => 'required|string',
            'db_port' => 'required|numeric',
            'db_database' => 'required|string',
            'db_username' => 'required|string',
            'db_password' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()]);
        }

        // Sanitize: strip surrounding quotes that may have been pasted in
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

            return response()->json(['success' => true, 'message' => 'Database connection successful!']);
        } catch (\PDOException $e) {
            return response()->json(['success' => false, 'message' => 'Connection failed: ' . $this->sanitizeError($e->getMessage())]);
        }
    }

    /**
     * Step 3: Save database config, create .env, generate key, run migrations.
     */
    public function environment(Request $request)
    {
        if ($this->isInstalled()) {
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
            return redirect()->route('install.database')->withErrors($validator)->withInput();
        }

        // Sanitize: strip surrounding quotes from all inputs
        $dbHost     = trimQuotes($request->db_host);
        $dbPort     = trimQuotes($request->db_port);
        $dbDatabase = trimQuotes($request->db_database);
        $dbUsername  = trimQuotes($request->db_username);
        $dbPassword  = trimQuotes($request->db_password ?? '');

        // Test connection with sanitized values
        try {
            new \PDO(
                "mysql:host={$dbHost};port={$dbPort};dbname={$dbDatabase}",
                $dbUsername,
                $dbPassword,
                [\PDO::ATTR_TIMEOUT => 5]
            );
        } catch (\PDOException $e) {
            return redirect()->route('install.database')
                ->withErrors(['db_host' => 'Database connection failed: ' . $this->sanitizeError($e->getMessage())])
                ->withInput();
        }

        // Generate APP_KEY (plain base64:... without quotes)
        $appKey = 'base64:' . base64_encode(random_bytes(32));

        // Detect APP_URL
        $appUrl = $request->getSchemeAndHttpHost();

        // Generate CRON_TOKEN
        $cronToken = Str::random(32);

        // Write .env file – using sanitized (unquoted) values
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
            'DB_HOST' => $dbHost,
            'DB_PORT' => $dbPort,
            'DB_DATABASE' => $dbDatabase,
            'DB_USERNAME' => $dbUsername,
            'DB_PASSWORD' => $dbPassword,
            'BROADCAST_DRIVER' => 'log',
            'CACHE_DRIVER' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'file',
            'SESSION_LIFETIME' => '120',
            'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => 'localhost',
            'MAIL_PORT' => '587',
            'MAIL_USERNAME' => 'null',
            'MAIL_PASSWORD' => 'null',
            'MAIL_ENCRYPTION' => 'null',
            'MAIL_FROM_ADDRESS' => 'noreply@' . $request->getHost(),
            'MAIL_FROM_NAME' => '${APP_NAME}',
            'CRON_TOKEN' => $cronToken,
        ]);

        $envPath = base_path('.env');
        file_put_contents($envPath, $envContent);
        chmod($envPath, 0600);

        // Store data in session for next steps
        $request->session()->put('install_env_written', true);
        $request->session()->put('install_cron_token', $cronToken);

        return redirect()->route('install.migrate');
    }

    /**
     * Step 4+5: Run migrations and seeders.
     */
    public function migrate(Request $request)
    {
        if ($this->isInstalled()) {
            return redirect('/');
        }

        if (! $request->session()->get('install_env_written')) {
            return redirect()->route('install.database');
        }

        $results = [];
        $errors = [];

        try {
            // Clear config cache to pick up new .env
            Artisan::call('config:clear');
            $results[] = 'Configuration cache cleared.';

            // Re-read the new .env
            app()->bootstrapWith([
                \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
                \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
            ]);

            // Reconfigure database with new credentials
            config([
                'database.connections.mysql.host' => env('DB_HOST'),
                'database.connections.mysql.port' => env('DB_PORT'),
                'database.connections.mysql.database' => env('DB_DATABASE'),
                'database.connections.mysql.username' => env('DB_USERNAME'),
                'database.connections.mysql.password' => env('DB_PASSWORD'),
            ]);
            DB::purge('mysql');
            DB::reconnect('mysql');

            // Run migrations
            Artisan::call('migrate', ['--force' => true]);
            $results[] = 'Database migrations completed.';
            $results[] = trim(Artisan::output());

            // Run seeders
            Artisan::call('db:seed', ['--force' => true]);
            $results[] = 'Database seeding completed.';
            $results[] = trim(Artisan::output());

            // Create passport keys
            try {
                Artisan::call('passport:install', ['--force' => true]);
                $results[] = 'Passport keys installed.';
            } catch (\Exception $e) {
                $results[] = 'Passport setup skipped: ' . $this->sanitizeError($e->getMessage());
            }

            // Create storage link
            try {
                Artisan::call('storage:link');
                $results[] = 'Storage link created.';
            } catch (\Exception $e) {
                $results[] = 'Storage link skipped (may already exist).';
            }

        } catch (\Exception $e) {
            $errors[] = 'Migration error: ' . $this->sanitizeError($e->getMessage());
            $errors[] = 'Check storage/logs/laravel.log for details.';

            return view('install.migrate', compact('results', 'errors'));
        }

        $request->session()->put('install_migrated', true);

        return view('install.migrate', compact('results', 'errors'));
    }

    /**
     * Step 6: Admin user creation form.
     */
    public function admin(Request $request)
    {
        if ($this->isInstalled()) {
            return redirect('/');
        }

        if (! $request->session()->get('install_migrated')) {
            return redirect()->route('install.database');
        }

        return view('install.admin');
    }

    /**
     * Step 6b: Create admin user.
     */
    public function createAdmin(Request $request)
    {
        if ($this->isInstalled()) {
            return redirect('/');
        }

        try {
            // Reconfigure database before validation (unique rules need DB)
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
            return redirect()->route('install.admin')->withErrors($validator)->withInput();
        }

        try {
            $user = User::create([
                'username' => $request->username,
                'email' => $request->email,
                'email_verified_at' => now(),
                'password' => Hash::make($request->password),
                'is_admin' => true,
                'is_enabled' => true,
            ]);

            $request->session()->put('install_admin_created', true);

            return redirect()->route('install.complete');
        } catch (\Exception $e) {
            return redirect()->route('install.admin')
                ->withErrors(['username' => 'Error creating admin: ' . $this->sanitizeError($e->getMessage())])
                ->withInput();
        }
    }

    /**
     * Step 7: Installation complete.
     */
    public function complete(Request $request)
    {
        if ($this->isInstalled()) {
            return redirect('/');
        }

        // Prevent skipping steps: admin must have been created
        if (! $request->session()->get('install_admin_created')) {
            return redirect()->route('install.admin');
        }

        // Read CRON_TOKEN BEFORE config:cache (env() returns null after caching)
        // trimQuotes() ensures no stray quotes leak into the displayed value.
        $cronToken = trimQuotes(config('app.cron_token') ?: env('CRON_TOKEN', ''));

        // Create lock file FIRST so that post_autoload.php sees it
        $lockFile = storage_path('installed.lock');
        file_put_contents($lockFile, json_encode([
            'installed_at' => now()->toIso8601String(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ]));

        // ---------------------------------------------------------------
        // Post-install: clear stale caches and run package:discover
        // During the initial "composer install" these were skipped because
        // .env / installed.lock did not exist yet. Now we catch up.
        // ---------------------------------------------------------------
        $postInstallErrors = [];

        try {
            Artisan::call('config:clear');
        } catch (\Exception $e) {
            $postInstallErrors[] = 'config:clear – ' . $this->sanitizeError($e->getMessage());
        }

        try {
            Artisan::call('route:clear');
        } catch (\Exception $e) {
            $postInstallErrors[] = 'route:clear – ' . $this->sanitizeError($e->getMessage());
        }

        try {
            Artisan::call('view:clear');
        } catch (\Exception $e) {
            $postInstallErrors[] = 'view:clear – ' . $this->sanitizeError($e->getMessage());
        }

        try {
            Artisan::call('package:discover', ['--ansi' => true]);
        } catch (\Exception $e) {
            $postInstallErrors[] = 'package:discover – ' . $this->sanitizeError($e->getMessage());
        }

        // Rebuild caches for performance
        // Note: route:cache is skipped because Closure-based routes (console.php) are not cacheable
        try {
            Artisan::call('config:cache');
            Artisan::call('view:cache');
        } catch (\Exception $e) {
            $postInstallErrors[] = 'cache rebuild – ' . $this->sanitizeError($e->getMessage());
        }

        if (! empty($postInstallErrors)) {
            \Illuminate\Support\Facades\Log::warning('Post-install commands had issues', $postInstallErrors);
        }

        // Clear install session data
        $request->session()->forget(['install_env_written', 'install_migrated', 'install_admin_created', 'install_cron_token']);

        return view('install.complete', compact('cronToken'));
    }

    /**
     * Check PHP requirements.
     */
    protected function checkRequirements(): array
    {
        $requirements = [];

        // PHP version
        $requirements[] = [
            'name' => 'PHP Version >= 8.1',
            'current' => PHP_VERSION,
            'passed' => version_compare(PHP_VERSION, '8.1.0', '>='),
        ];

        // Required extensions
        $extensions = [
            'pdo' => 'PDO',
            'pdo_mysql' => 'PDO MySQL',
            'mbstring' => 'Mbstring',
            'openssl' => 'OpenSSL',
            'tokenizer' => 'Tokenizer',
            'ctype' => 'Ctype',
            'json' => 'JSON',
            'fileinfo' => 'Fileinfo',
            'xml' => 'XML',
            'curl' => 'cURL',
            'dom' => 'DOM',
            'bcmath' => 'BCMath',
        ];

        foreach ($extensions as $ext => $label) {
            $requirements[] = [
                'name' => "PHP Extension: {$label}",
                'current' => extension_loaded($ext) ? 'Installed' : 'Missing',
                'passed' => extension_loaded($ext),
            ];
        }

        // Optional extensions
        $requirements[] = [
            'name' => 'PHP Extension: Imagick (optional, for starmap)',
            'current' => extension_loaded('imagick') ? 'Installed' : 'Not installed',
            'passed' => true, // Optional, always passes
        ];

        return $requirements;
    }

    /**
     * Check directory permissions.
     */
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
            $writable = is_dir($path) && is_writable($path);
            $permissions[] = [
                'name' => $label,
                'current' => $writable ? 'Writable' : 'Not writable',
                'passed' => $writable,
            ];
        }

        return $permissions;
    }

    /**
     * Check if application is installed.
     */
    protected function isInstalled(): bool
    {
        return file_exists(storage_path('installed.lock'));
    }

    /**
     * Build .env file content.
     *
     * Quoting rules:
     *  - Quote ONLY when the value contains characters that break .env parsing:
     *    spaces, #, or literal double-quotes.
     *  - Do NOT quote base64 keys, passwords, or tokens just because they
     *    contain /, +, or trailing = (these are safe unquoted in .env).
     */
    protected function buildEnvContent(array $values): string
    {
        $lines = [];
        $lastGroup = '';

        foreach ($values as $key => $value) {
            // Ensure the raw value has no surrounding quotes from previous writes
            $value = trimQuotes($value);

            $group = explode('_', $key)[0];
            if ($lastGroup && $group !== $lastGroup) {
                $lines[] = '';
            }
            $lastGroup = $group;

            // Only quote when the value contains characters that would break
            // .env parsing: spaces, # (comment char), or literal " inside the value.
            $needsQuoting = str_contains($value, ' ')
                || str_contains($value, '#')
                || str_contains($value, '"');

            if ($needsQuoting) {
                // Escape existing double quotes inside the value
                $escaped = str_replace('"', '\\"', $value);
                $value = '"' . $escaped . '"';
            }

            $lines[] = "{$key}={$value}";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Sanitize error messages to prevent leaking secrets.
     */
    protected function sanitizeError(string $message): string
    {
        // Remove any password or credential information
        $message = preg_replace('/password[\'"\s]*[:=][\'"\s]*[^\s\'"]*/i', 'password=***', $message);
        $message = preg_replace('/using password: \w+/i', 'using password: ***', $message);

        return Str::limit($message, 200);
    }

    /**
     * Reconnect database from current .env.
     */
    protected function reconnectDatabase(): void
    {
        // Force reload .env
        $dotenv = \Dotenv\Dotenv::createMutable(base_path());
        $dotenv->load();

        config([
            'database.connections.mysql.host' => env('DB_HOST'),
            'database.connections.mysql.port' => env('DB_PORT'),
            'database.connections.mysql.database' => env('DB_DATABASE'),
            'database.connections.mysql.username' => env('DB_USERNAME'),
            'database.connections.mysql.password' => env('DB_PASSWORD'),
        ]);

        DB::purge('mysql');
        DB::reconnect('mysql');
    }
}

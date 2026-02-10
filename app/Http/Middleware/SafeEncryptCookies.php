<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Safe cookie encryption wrapper for the installer middleware group.
 *
 * During fresh installation, APP_KEY may not exist yet. The standard
 * EncryptCookies middleware depends on the Encrypter service which
 * requires a valid APP_KEY — resolving it without a key throws
 * Illuminate\Encryption\MissingAppKeyException.
 *
 * This middleware wraps EncryptCookies: when APP_KEY is available and
 * valid, it delegates to the real EncryptCookies. When APP_KEY is
 * missing or invalid, it passes the request through WITHOUT cookie
 * encryption, allowing the installer to function.
 *
 * SECURITY NOTE: This middleware is ONLY used in the 'installer'
 * middleware group. Regular 'web' routes still use the standard
 * EncryptCookies which enforces APP_KEY.
 */
class SafeEncryptCookies
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if APP_KEY is available and the Encrypter can be resolved
        if ($this->hasValidEncryptionKey()) {
            try {
                // Delegate to the real EncryptCookies middleware
                $middleware = app()->make(\App\Http\Middleware\EncryptCookies::class);
                return $middleware->handle($request, $next);
            } catch (\Throwable $e) {
                // Encrypter failed despite key check — fall through to unencrypted
                $this->logWarning('EncryptCookies failed: ' . $e->getMessage());
            }
        }

        // No valid APP_KEY or Encrypter failed — pass through without encryption.
        // Sessions will still work (file-based session ID in a plain cookie).
        return $next($request);
    }

    /**
     * Check if a valid encryption key is configured.
     */
    protected function hasValidEncryptionKey(): bool
    {
        try {
            $key = config('app.key') ?: env('APP_KEY');

            if (empty($key)) {
                return false;
            }

            // Quick sanity check: key should be base64-encoded or raw 32+ chars
            if (str_starts_with($key, 'base64:')) {
                $decoded = base64_decode(substr($key, 7), true);
                return $decoded !== false && strlen($decoded) >= 16;
            }

            return strlen($key) >= 16;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Log a warning to the installer log.
     */
    protected function logWarning(string $message): void
    {
        $logPath = storage_path('logs/installer.log');
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($logPath, "[{$timestamp}] SafeEncryptCookies: {$message}\n", FILE_APPEND);
    }
}

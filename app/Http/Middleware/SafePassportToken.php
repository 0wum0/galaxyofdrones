<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Safely wraps Passport's CreateFreshApiToken middleware.
 *
 * Before Passport is installed (no keys, no oauth tables), the original
 * middleware throws an exception. This wrapper catches that and lets the
 * request through, so the app works during installation and on fresh
 * deployments before `passport:install` has been run.
 */
class SafePassportToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip entirely if Passport middleware class doesn't exist
        if (!class_exists(\Laravel\Passport\Http\Middleware\CreateFreshApiToken::class)) {
            return $next($request);
        }

        try {
            $middleware = app()->make(\Laravel\Passport\Http\Middleware\CreateFreshApiToken::class);
            return $middleware->handle($request, $next);
        } catch (\Throwable $e) {
            // Passport not ready (no keys, no tables, etc.) – continue without API token
            return $next($request);
        }
    }
}

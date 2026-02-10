<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array
     */
    protected $except = [];

    /**
     * Handle an incoming request.
     *
     * Overrides the parent to catch MissingAppKeyException during fresh
     * installation when no .env / APP_KEY exists yet. Without this override,
     * the very first request to the site would crash with a 500 error
     * because EncryptCookies can't resolve the Encrypter without a key,
     * and the CheckInstalled middleware (which redirects to /install) runs
     * AFTER EncryptCookies in the middleware pipeline.
     *
     * Once .env is created and APP_KEY is set (by the installer), this
     * override becomes a no-op and the parent handles everything normally.
     */
    public function handle($request, Closure $next, ...$params)
    {
        try {
            return parent::handle($request, $next);
        } catch (\Illuminate\Encryption\MissingAppKeyException $e) {
            // APP_KEY not set — pass through without encryption.
            // This only happens during fresh install before .env is created.
            return $next($request);
        } catch (\RuntimeException $e) {
            // Catch broader encryption failures (corrupt key, wrong cipher, etc.)
            if (str_contains($e->getMessage(), 'key') || str_contains($e->getMessage(), 'encrypt')) {
                return $next($request);
            }
            throw $e;
        }
    }
}

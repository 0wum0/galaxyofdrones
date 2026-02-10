<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prevent CDN / edge / browser caching of pages that contain CSRF tokens.
 *
 * Hostinger's hCDN (and other edge/proxy caches) may serve a stale HTML page
 * containing an old CSRF token to the user. When the user then POSTs, the
 * token in the form no longer matches the session token, causing a 419.
 *
 * This middleware adds aggressive no-cache headers to ensure the HTML page
 * is always fetched fresh from the origin.
 *
 * Apply to any GET route that renders a form with @csrf (at minimum: /start).
 */
class NoStoreForCsrfPages
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}

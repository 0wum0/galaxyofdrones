<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Diagnostic middleware: logs full CSRF / session context at INFO level
 * BEFORE the controller runs. Apply only to routes under investigation
 * (e.g. GET /start, POST /start).
 *
 * Remove this middleware once 419 issues are resolved.
 */
class LogCsrfContext
{
    public function handle(Request $request, Closure $next)
    {
        $sessionCookieName = config('session.cookie');

        // Collect all request cookie names with their value lengths
        $cookieInfo = [];
        foreach ($request->cookies->all() as $name => $value) {
            $cookieInfo[$name] = is_string($value) ? strlen($value) : 'non-string';
        }

        // Session token details
        $sessionTokenPresent = false;
        $sessionTokenLength  = 0;
        $sessionId           = 'NO_SESSION';
        if ($request->hasSession()) {
            $sessionId    = $request->session()->getId();
            $sessionToken = $request->session()->token();
            if ($sessionToken) {
                $sessionTokenPresent = true;
                $sessionTokenLength  = strlen($sessionToken);
            }
        }

        Log::info('LogCsrfContext — pre-controller diagnostic', [
            // Request context
            'url'                    => $request->fullUrl(),
            'method'                 => $request->method(),
            'host'                   => $request->getHost(),
            'scheme'                 => $request->getScheme(),
            'is_secure'              => $request->isSecure(),
            'ip'                     => $request->ip(),
            'referer'                => $request->header('referer'),
            'user_agent'             => $request->userAgent(),

            // Auth
            'user_id'                => $request->user()?->id,

            // Session
            'session_id'             => $sessionId,
            'session_driver'         => config('session.driver'),
            'session_cookie_name'    => $sessionCookieName,
            'session_domain'         => config('session.domain'),
            'session_secure'         => config('session.secure'),
            'session_samesite'       => config('session.same_site'),

            // Token presence
            'session_token_present'  => $sessionTokenPresent,
            'session_token_length'   => $sessionTokenLength,
            'input_token_present'    => (bool) $request->input('_token'),
            'input_token_length'     => $request->input('_token') ? strlen($request->input('_token')) : 0,
            'header_X_CSRF_TOKEN'    => $request->header('X-CSRF-TOKEN') ? 'present(' . strlen($request->header('X-CSRF-TOKEN')) . ')' : 'MISSING',
            'header_X_XSRF_TOKEN'   => $request->header('X-XSRF-TOKEN') ? 'present(' . strlen($request->header('X-XSRF-TOKEN')) . ')' : 'MISSING',

            // Cookie details
            'has_session_cookie'     => $request->cookies->has($sessionCookieName),
            'request_cookies'        => $cookieInfo,

            // App config
            'app_url'                => config('app.url'),
        ]);

        // Execute the request (controller)
        $response = $next($request);

        // Log response Set-Cookie headers (helps diagnose cookie overwrite / domain issues)
        $setCookieHeaders = $response->headers->allPreserveCaseWithoutCookies();
        $responseCookies  = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $responseCookies[] = [
                'name'     => $cookie->getName(),
                'domain'   => $cookie->getDomain(),
                'path'     => $cookie->getPath(),
                'secure'   => $cookie->isSecure(),
                'samesite' => $cookie->getSameSite(),
                'httponly'  => $cookie->isHttpOnly(),
            ];
        }

        if (!empty($responseCookies)) {
            Log::info('LogCsrfContext — response cookies', [
                'url'              => $request->fullUrl(),
                'method'           => $request->method(),
                'response_cookies' => $responseCookies,
            ]);
        }

        return $response;
    }
}

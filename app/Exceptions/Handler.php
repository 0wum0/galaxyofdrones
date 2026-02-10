<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        // ---------------------------------------------------------------
        // Force-log CSRF 419 errors with FULL diagnostic context.
        //
        // Laravel's base handler silently suppresses TokenMismatchException
        // via $internalDontReport, so 419s never appear in laravel.log.
        // This renderable() explicitly logs them at ERROR level (to survive
        // LOG_LEVEL=error in .env) with all session/cookie/proxy details
        // needed to diagnose Hostinger shared-hosting cookie issues.
        // ---------------------------------------------------------------
        $this->renderable(function (TokenMismatchException $e, $request) {
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

            Log::error('CSRF TokenMismatchException (419)', [
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
                'session_lifetime'       => config('session.lifetime'),

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
                'app_env'                => config('app.env'),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'CSRF token mismatch. Please refresh the page and try again.',
                ], 419);
            }

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Your session has expired. Please try again.');
        });

        $this->reportable(function (Throwable $e) {});
    }
}

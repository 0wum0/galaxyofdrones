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
        // Force-log CSRF 419 errors with full diagnostic context.
        //
        // Laravel's base handler silently suppresses TokenMismatchException
        // via $internalDontReport, so 419s never appear in laravel.log.
        // This renderable() explicitly logs them at ERROR level (to survive
        // LOG_LEVEL=error in .env) with all session/cookie details needed
        // to diagnose Hostinger shared-hosting cookie issues.
        // ---------------------------------------------------------------
        $this->renderable(function (TokenMismatchException $e, $request) {
            $sessionCookieName = config('session.cookie');

            Log::error('CSRF TokenMismatchException (419)', [
                'url'               => $request->fullUrl(),
                'method'            => $request->method(),
                'user_id'           => $request->user()?->id,
                'session_id'        => $request->hasSession() ? $request->session()->getId() : 'NO_SESSION',
                'session_token'     => $request->hasSession() ? ($request->session()->token() ? 'present' : 'MISSING') : 'NO_SESSION',
                'input_token'       => $request->input('_token') ? 'present' : 'MISSING',
                'header_token'      => $request->header('X-CSRF-TOKEN') ? 'present' : 'MISSING',
                'has_session_cookie' => $request->cookies->has($sessionCookieName),
                'cookie_name'       => $sessionCookieName,
                'session_domain'    => config('session.domain'),
                'session_secure'    => config('session.secure'),
                'session_samesite'  => config('session.same_site'),
                'session_driver'    => config('session.driver'),
                'app_url'           => config('app.url'),
                'referer'           => $request->header('referer'),
                'user_agent'        => $request->userAgent(),
                'ip'                => $request->ip(),
                'is_secure'         => $request->isSecure(),
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

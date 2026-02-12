<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array
     */
    protected $except = [];

    /**
     * Whether encryption is available (APP_KEY set and valid).
     */
    private bool $encrypterAvailable = false;

    /**
     * Override the constructor to NOT require EncrypterContract as parameter.
     *
     * The parent constructor requires EncrypterContract which the Laravel
     * container auto-resolves. If APP_KEY is not set (fresh install before
     * .env is created), the Encrypter service throws MissingAppKeyException
     * during container resolution — before handle() is ever called.
     *
     * By using a parameterless constructor and resolving manually, we can
     * catch the exception and gracefully degrade to no-encryption mode.
     * This allows the CheckInstalled middleware (later in the pipeline)
     * to redirect to the installer.
     */
    public function __construct()
    {
        try {
            $encrypter = app(\Illuminate\Contracts\Encryption\Encrypter::class);
            parent::__construct($encrypter);
            $this->encrypterAvailable = true;
        } catch (\Throwable $e) {
            // APP_KEY missing or invalid — encryption not available.
            // This is expected during fresh install before .env is created.
            $this->encrypterAvailable = false;
        }
    }

    /**
     * Handle an incoming request.
     *
     * When the Encrypter is not available (no APP_KEY), passes the request
     * through without cookie encryption. This allows CheckInstalled to
     * redirect to the installer and prevents 500 errors on fresh deploys.
     *
     * If a cookie fails to decrypt (e.g. APP_KEY changed, corrupted cookie),
     * we clear the bad cookies from BOTH the request (so downstream middleware
     * like StartSession and VerifyCsrfToken start fresh) AND the response
     * (so the browser discards them). This prevents the 419/whitescreen loop
     * that occurred when raw encrypted values leaked into the session layer.
     */
    public function handle($request, Closure $next)
    {
        if (! $this->encrypterAvailable) {
            return $next($request);
        }

        try {
            return parent::handle($request, $next);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Cookie corruption (e.g. APP_KEY rotation).
            // 1) Clear ALL cookies from the request so StartSession creates
            //    a brand-new session and VerifyCsrfToken sees a clean slate.
            $cookieNames = $request->cookies->keys();
            foreach ($cookieNames as $name) {
                $request->cookies->remove($name);
            }

            // 2) Continue the pipeline with the cleaned request.
            $response = $next($request);

            // 3) Tell the browser to forget the corrupted cookies.
            foreach ($cookieNames as $name) {
                $response->headers->setCookie(
                    cookie()->forget($name)
                );
            }

            return $response;
        } catch (\Throwable $e) {
            // Any other encryption-related error: clear cookies and continue.
            $cookieNames = $request->cookies->keys();
            foreach ($cookieNames as $name) {
                $request->cookies->remove($name);
            }
            return $next($request);
        }
    }
}

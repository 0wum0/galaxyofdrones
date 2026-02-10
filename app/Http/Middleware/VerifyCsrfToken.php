<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * The installer routes are excluded as a safety-net for environments
     * (e.g. Hostinger) where proxy/session issues may still cause 419
     * errors even after the TrustProxies fix.  The installer is protected
     * by the isInstalled() + isUnlocked() checks in InstallController.
     *
     * The cron endpoint is token-protected and doesn't use sessions.
     *
     * @var array
     */
    protected $except = [
        'install/*',
        'cron/*',
    ];
}

<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * Trust all proxies – required for Hostinger and other shared/cloud
     * hosts that sit behind load-balancers or reverse proxies.
     *
     * Can be overridden via TRUSTED_PROXIES env variable (comma-separated).
     * If not set, defaults to '*' (trust all).
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * Uses the Symfony Request constants (HEADER_X_FORWARDED_ALL was removed
     * in Symfony 6.0). This combination covers all X-Forwarded-* headers
     * and ensures HTTPS / X-Forwarded-Proto is correctly detected behind
     * shared-hosting reverse proxies.
     *
     * @var int
     */
    protected $headers =
        SymfonyRequest::HEADER_X_FORWARDED_FOR |
        SymfonyRequest::HEADER_X_FORWARDED_HOST |
        SymfonyRequest::HEADER_X_FORWARDED_PORT |
        SymfonyRequest::HEADER_X_FORWARDED_PROTO |
        SymfonyRequest::HEADER_X_FORWARDED_PREFIX;

    /**
     * Bootstrap the proxy configuration from environment.
     */
    public function __construct()
    {
        $envProxies = env('TRUSTED_PROXIES');

        if ($envProxies) {
            // Support comma-separated list: "10.0.0.1,10.0.0.2" or "*"
            $this->proxies = $envProxies === '*'
                ? '*'
                : array_map('trim', explode(',', $envProxies));
        } else {
            // Default: trust all proxies (safe for shared hosting behind LB)
            $this->proxies = '*';
        }
    }
}

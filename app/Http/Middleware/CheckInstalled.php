<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckInstalled
{
    /**
     * Handle an incoming request.
     *
     * Redirects to the installer if the application is not yet installed.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isInstalled() && ! $request->is('install*')) {
            return redirect()->route('install.index');
        }

        return $next($request);
    }

    /**
     * Check if the application is installed.
     */
    protected function isInstalled(): bool
    {
        return file_exists(storage_path('installed.lock'));
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAppIsInstalled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('setup.*') || $request->is('up')) {
            return $next($request);
        }

        if (! Installation::isInstalled()) {
            return redirect()->route('setup.show');
        }

        return $next($request);
    }
}

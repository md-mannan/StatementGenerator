<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ConfigureSessionCookie
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isSecure = $request->isSecure()
            || strtolower((string) $request->header('X-Forwarded-Proto')) === 'https';

        config(['session.secure' => $isSecure]);

        $appUrl = config('app.url');

        if (is_string($appUrl) && str_starts_with($appUrl, 'https://') && $isSecure) {
            URL::forceScheme('https');
        }

        return $next($request);
    }
}

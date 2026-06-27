<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHttps
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appUrl = config('app.url');

        if (
            ! app()->isProduction()
            || ! is_string($appUrl)
            || ! str_starts_with($appUrl, 'https://')
            || $this->requestIsSecure($request)
        ) {
            return $next($request);
        }

        return redirect()->secure($request->getRequestUri());
    }

    private function requestIsSecure(Request $request): bool
    {
        return $request->isSecure()
            || strtolower((string) $request->header('X-Forwarded-Proto')) === 'https';
    }
}

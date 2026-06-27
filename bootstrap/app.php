<?php

use App\Http\Middleware\ConfigureSessionCookie;
use App\Http\Middleware\EnsureAppIsInstalled;
use App\Http\Middleware\EnsureHttps;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfInstalled;
use App\Support\Installation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/setup.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->prependToPriorityList(StartSession::class, ConfigureSessionCookie::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->redirectGuestsTo(function () {
            return Installation::isInstalled()
                ? route('login')
                : route('setup.show');
        });
        $middleware->redirectUsersTo(fn () => route('dashboard'));

        $middleware->alias([
            'installed' => EnsureAppIsInstalled::class,
            'setup' => RedirectIfInstalled::class,
        ]);

        $middleware->web(prepend: [
            EnsureAppIsInstalled::class,
            EnsureHttps::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

<?php

use App\Exceptions\InsufficientStockException;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolveStore;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'store' => ResolveStore::class,
        ]);

        // Render (and similar hosts) end HTTPS at their proxy and forward plain
        // HTTP. Trusting it makes Laravel see the real https URL, which signed
        // links (report QR codes) and generated URLs depend on.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A sold-out product is an expected outcome, not an application error.
        $exceptions->dontReport(InsufficientStockException::class);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

<?php

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
        // Heroku terminates TLS and forwards over HTTP with X-Forwarded-* headers, and
        // the dyno is only reachable through that router — so trust it. Without this,
        // $request->ip() is the router's IP, collapsing every per-IP rate-limit bucket
        // (AppServiceProvider) into one global bucket, and $request->isSecure() is false,
        // which can loop Filament login under SESSION_SECURE_COOKIE=true.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

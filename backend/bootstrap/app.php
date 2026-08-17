<?php

use Filament\Http\Middleware\IdentifyTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Listeners are wired explicitly in AppServiceProvider (Event::listen) so the
    // wiring stays greppable. Auto-discovery would register each app/Listeners class
    // a second time — one OrderPlaced dispatch then alerts the admin twice (#52).
    // Must be `false`: an empty paths array falls back to scanning app/Listeners.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        // Heroku terminates TLS and forwards over HTTP with X-Forwarded-* headers, and
        // the dyno is only reachable through that router — so trust it. Without this,
        // $request->ip() is the router's IP, collapsing every per-IP rate-limit bucket
        // (AppServiceProvider) into one global bucket, and $request->isSecure() is false,
        // which can loop Filament login under SESSION_SECURE_COOKIE=true.
        $middleware->trustProxies(at: '*');

        // Tenant resolution is per-route (Step 24/25a): ResolveTenant on the
        // /api/v1/{shop} group binds the tenant from the path; the panel resolves
        // it from /admin/{tenant} via Filament's IdentifyTenant, mirrored into
        // TenantContext by SyncTenantContextFromFilament.

        // Laravel's default middleware priority runs SubstituteBindings before any
        // unlisted middleware, which would resolve the invoice/payment-proof
        // {order} bindings BEFORE IdentifyTenant has set a tenant — every request
        // would throw TenantNotSetException. Identify the tenant first, so those
        // implicit bindings run under the tenant scope and a cross-shop id is a
        // 404, never a leak (TenantIsolationTest pins this).
        $middleware->prependToPriorityList(SubstituteBindings::class, IdentifyTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

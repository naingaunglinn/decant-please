<?php

namespace App\Http\Middleware;

use App\Models\ShopDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0004: the CORS allowlist must accept every verified shop domain, and
 * HandleCors reads config('cors') fresh inside handle() on every request — so the
 * override point is the config repository, per request, just before it runs.
 * Prepended to the global stack in bootstrap/app.php, ahead of HandleCors.
 *
 * The env FRONTEND_URL origins stay as the platform base; the table-derived
 * origins (60s cache, busted by ShopDomain's model hooks) merge over them. The
 * pristine base is captured once per process so a deleted domain drops out of the
 * next merge instead of lingering in the mutated config — that matters in
 * long-lived processes (tests, a future Octane); under FPM the config is rebuilt
 * every request anyway. config:cache-safe: reads the cached config array and the
 * database, never env() at request time.
 */
class MergeShopDomainCorsOrigins
{
    private static ?array $platformOrigins = null;

    public function handle(Request $request, Closure $next): Response
    {
        // Only the paths HandleCors covers (config cors.paths — api/*) speak CORS;
        // everything else skips the lookup. That spares every panel/web request a
        // query, and keeps database-less requests (the health check, a test with
        // no migrated schema) from touching a table that may not exist yet.
        $speaksCors = collect(config('cors.paths', []))
            ->contains(fn (string $path): bool => $request->is($path === '/' ? $path : trim($path, '/')));

        if (! $speaksCors) {
            return $next($request);
        }

        self::$platformOrigins ??= config('cors.allowed_origins', []);

        config(['cors.allowed_origins' => array_values(array_unique(array_merge(
            self::$platformOrigins,
            ShopDomain::corsOrigins(),
        )))]);

        return $next($request);
    }
}

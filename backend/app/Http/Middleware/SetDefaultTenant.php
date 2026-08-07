<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interim tenant resolution (Step 23 §5), explicitly temporary. With no {shop} in
 * any URL yet, every request must still get a tenant or every tenant-owned query
 * throws. Resolves the sole shop by SHOP_SLUG and sets it ONLY IF unset — so a test
 * that pre-set a tenant, or a future resolver that ran earlier, wins.
 *
 * Replaced on the API side by ResolveTenant (path {shop}) in Step 24, and on the
 * panel side by Filament tenancy in Step 25. Deliberately not cached: it's one query
 * for the interim window, and caching a Shop model across test-DB resets leaks stale
 * ids between tests.
 */
class SetDefaultTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->has()) {
            $slug = config('app.shop_slug');
            $shop = Shop::query()->where('slug', $slug)->where('is_active', true)->first();

            if ($shop) {
                $this->context->set($shop);
            }
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Multi-tenancy Step 24 (ADR-001): the tenant is the {shop} path segment
 * (/api/v1/{shop}/…), visible in every log line, stack trace, and curl. Resolves
 * the slug to an active shop, binds it into TenantContext, and 404s on an unknown
 * or inactive shop — the same generic-404 discipline as tracking, so the endpoint
 * is no shop-enumeration oracle beyond what the storefront URL already reveals.
 *
 * The API-side half of per-route tenant resolution; the panel's half is Filament's
 * IdentifyTenant, mirrored into TenantContext by SyncTenantContextFromFilament
 * (Step 25a). The interim SetDefaultTenant middleware both replaced is deleted.
 */
class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // ->active() is the one lifecycle seam (scopeActive === status live, Step 34):
        // only a live shop resolves; onboarding/suspended/archived 404 as before.
        // The resolution architecture is unchanged — only the servable predicate moved
        // from the retired is_active boolean to the status enum.
        $shop = Shop::query()
            ->where('slug', $request->route('shop'))
            ->active()
            ->first();

        abort_if($shop === null, 404);

        $this->context->set($shop);

        // Drop {shop} from the route params so controller signatures and implicit
        // route-model binding only ever see their own parameters.
        $request->route()->forgetParameter('shop');

        return $next($request);
    }
}

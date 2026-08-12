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
 * Replaces the interim SetDefaultTenant on the API side; the panel keeps its own
 * interim middleware until Filament tenancy lands (Step 25).
 */
class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $shop = Shop::query()
            ->where('slug', $request->route('shop'))
            ->where('is_active', true)
            ->first();

        abort_if($shop === null, 404);

        $this->context->set($shop);

        // Drop {shop} from the route params so controller signatures and implicit
        // route-model binding only ever see their own parameters.
        $request->route()->forgetParameter('shop');

        return $next($request);
    }
}

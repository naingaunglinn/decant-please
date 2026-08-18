<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StorefrontHostResource;
use App\Models\ShopDomain;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-0004: host → shop resolution for the shared storefront deployment. A platform
 * endpoint, deliberately OUTSIDE /api/v1/{shop} — this call is what produces the
 * slug — so no TenantContext is set here and only unscoped platform tables are
 * read. Unknown, unverified, and inactive-shop hosts all return the same generic
 * 404: the endpoint confirms nothing beyond what visiting the domain itself would.
 */
class StorefrontHostController extends Controller
{
    public function __invoke(string $host): StorefrontHostResource
    {
        $domain = ShopDomain::query()
            ->where('host', ShopDomain::normalizeHost($host))
            ->whereNotNull('verified_at')
            ->whereHas('shop', fn (Builder $query) => $query->active())
            ->with('shop.domains')
            ->first();

        abort_if($domain === null, 404);

        return new StorefrontHostResource($domain);
    }
}

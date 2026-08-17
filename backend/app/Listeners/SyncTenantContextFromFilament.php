<?php

namespace App\Listeners;

use App\Models\Shop;
use App\Support\TenantContext;
use Filament\Events\TenantSet;
use Illuminate\Support\Facades\URL;

/**
 * Multi-tenancy Step 25a bridge. Filament resolves the panel's tenant (from the
 * /admin/{tenant} slug, in IdentifyTenant) and fires TenantSet; we mirror it into
 * our own TenantContext so BelongsToShop's app scope — which is engine-agnostic and
 * knows nothing about Filament — sees the same shop. IdentifyTenant is ordered
 * before SubstituteBindings (bootstrap/app.php), so the {order} bindings on the
 * invoice/proof routes resolve under the tenant scope.
 *
 * The tenant also becomes the URL-generation default for the {tenant} route
 * parameter, so plain route('filament.admin.orders.…', $order) calls keep working
 * now that those routes live under /admin/{tenant} — the same mechanism
 * tests/TestCase.php uses.
 *
 * Wired explicitly in AppServiceProvider (event discovery is off, #52).
 */
class SyncTenantContextFromFilament
{
    public function __construct(private TenantContext $context) {}

    public function handle(TenantSet $event): void
    {
        $tenant = $event->getTenant();

        if ($tenant instanceof Shop) {
            $this->context->set($tenant);
            URL::defaults(['tenant' => $tenant->slug]);
        }
    }
}

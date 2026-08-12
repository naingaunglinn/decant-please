<?php

namespace App\Listeners;

use App\Models\Shop;
use App\Support\TenantContext;
use Filament\Events\TenantSet;

/**
 * Multi-tenancy Step 25a bridge. Filament resolves the panel's tenant (from the
 * /admin/{tenant} slug, in IdentifyTenant) and fires TenantSet; we mirror it into
 * our own TenantContext so BelongsToShop's app scope — which is engine-agnostic and
 * knows nothing about Filament — sees the same shop. TenantSet fires before route
 * model binding for other params ({order} on the invoice/proof routes), so those
 * bindings are tenant-scoped too.
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
        }
    }
}

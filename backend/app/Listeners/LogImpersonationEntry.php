<?php

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Support\AuditLogger;
use App\Support\Impersonation;
use Filament\Events\TenantSet;

/**
 * Step 34 §3 — writes exactly one `panel.enter` audit event when a studio operator
 * lands in a shop that isn't theirs. Listens to Filament's TenantSet (registered in
 * AppServiceProvider AFTER SyncTenantContextFromFilament, so TenantContext is already
 * mirrored when this runs), and de-dups via Impersonation::markEnteredIfNew() so the
 * per-request TenantSet fires log once per entry/switch, not once per page load. A
 * normal owner in their own shop is not impersonating → no event.
 */
class LogImpersonationEntry
{
    public function __construct(
        private Impersonation $impersonation,
        private AuditLogger $logger,
    ) {}

    public function handle(TenantSet $event): void
    {
        if ($this->impersonation->markEnteredIfNew()) {
            $this->logger->log(AuditAction::PanelEnter, $this->impersonation->shop());
        }
    }
}

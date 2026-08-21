<?php

namespace App\Support;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Step 34 §3 — impersonation = a studio operator (studio_admin, Shield super_admin)
 * operating a shop's /admin panel that they do NOT belong to. This is the single
 * place that answers "is this request impersonating, and has the operator taken
 * control?" It reads auth() + TenantContext + the session and stores nothing about
 * which shop a request belongs to — TenantContext stays the sole tenant identity.
 *
 * The write guard keys off ENTRY, not raw detection. `operatingForeignShop()` (studio
 * operator in a non-member shop) is necessary but not sufficient: the guard only
 * engages once the operator has actually ENTERED the shop's panel — recorded in the
 * session when Filament fires TenantSet (LogImpersonationEntry → markEnteredIfNew).
 * That entry signal fires reliably in real /admin requests but NOT for the public API,
 * console, or test data-setup that never enters a panel — so those write freely and
 * are never mislabelled as impersonation.
 *
 * Guardrail, not a boundary. Default is read-only; the model-layer guard blocks
 * writes until the operator explicitly *takes control* (audited). Because a
 * studio_admin is a Shield super_admin who can take control at will, this prevents
 * *accidental* writes and forces an auditable intent step — it is NOT a
 * tenant-isolation mechanism. Isolation stays BelongsToShop + TenantContext: a write
 * after take-control still lands in the current tenant, stamped by BelongsToShop.
 *
 * Take-control is per-shop by construction: one session key holds the shop the
 * operator took control of, so switching shops (TenantContext changes) drops back to
 * read-only automatically. Bound as a singleton (per request), like TenantContext.
 */
class Impersonation
{
    private const CONTROL_KEY = 'impersonation.control_shop_id';

    private const ENTERED_KEY = 'impersonation.entered_shop_id';

    public function __construct(private TenantContext $tenant) {}

    /**
     * Impersonating AND has entered this shop's panel. The write guard uses this so a
     * write is guarded only after a real panel entry — never in the API/console/tests
     * that don't enter a panel. False for a normal owner (a member) and for guests.
     */
    public function active(): bool
    {
        return $this->operatingForeignShop()
            && Session::get(self::ENTERED_KEY) === $this->tenant->id();
    }

    /** The impersonated shop, or null when not impersonating. */
    public function shop(): ?Shop
    {
        return $this->active() ? $this->tenant->get() : null;
    }

    /** Impersonating AND has not taken control of the current shop → writes blocked. */
    public function isReadOnly(): bool
    {
        return $this->active() && ! $this->hasControl();
    }

    /** Take-control applies to the CURRENT shop only; a shop switch resets it. */
    public function hasControl(): bool
    {
        return $this->active()
            && Session::get(self::CONTROL_KEY) === $this->tenant->id();
    }

    /** Flip the current shop to write-enabled (the caller logs the take_control event). */
    public function takeControl(): void
    {
        if ($this->active()) {
            Session::put(self::CONTROL_KEY, $this->tenant->id());
        }
    }

    public function release(): void
    {
        Session::forget(self::CONTROL_KEY);
    }

    /**
     * Called on TenantSet (panel entry). Records the shop the FIRST time the operator
     * lands in it and returns true so the caller logs exactly one panel.enter — so
     * repeated TenantSet events in the same shop don't re-log, but a switch does. A
     * normal owner in their own shop is not operating a foreign shop → false → no event.
     */
    public function markEnteredIfNew(): bool
    {
        if (! $this->operatingForeignShop()) {
            return false;
        }

        if (Session::get(self::ENTERED_KEY) === $this->tenant->id()) {
            return false;
        }

        Session::put(self::ENTERED_KEY, $this->tenant->id());

        return true;
    }

    /** A studio operator standing in a shop that is not theirs (pre-entry detection). */
    private function operatingForeignShop(): bool
    {
        $user = Auth::user();
        $shop = $this->tenant->get();

        return $user instanceof User
            && $shop instanceof Shop
            && $user->hasRole('studio_admin')
            && ! $user->shops()->whereKey($shop->getKey())->exists();
    }
}

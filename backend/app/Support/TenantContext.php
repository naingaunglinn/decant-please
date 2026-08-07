<?php

namespace App\Support;

use App\Models\Shop;

/**
 * The one place "which shop is this request for?" lives. Bound as a scoped
 * singleton (AppServiceProvider), so it is a clean slate per request/command and
 * per test. BelongsToShop's global scope and creating hook both read it.
 *
 * withoutTenancy() is the deliberate, budgeted escape hatch (design-doc §8): it
 * flips a bypass flag the scope honours, and always restores it in a finally so a
 * throwing callback can't leave tenancy disabled for the rest of the request.
 */
class TenantContext
{
    private ?Shop $shop = null;

    private bool $bypass = false;

    public function set(?Shop $shop): void
    {
        $this->shop = $shop;
    }

    public function get(): ?Shop
    {
        return $this->shop;
    }

    public function has(): bool
    {
        return $this->shop !== null;
    }

    public function id(): ?int
    {
        return $this->shop?->id;
    }

    /** The per-shop suffix for cache keys and storage prefixes (design-doc §7). */
    public function slug(): ?string
    {
        return $this->shop?->slug;
    }

    public function bypassing(): bool
    {
        return $this->bypass;
    }

    /**
     * Run $callback with the tenant scope disabled — for the few cross-shop reads
     * and writes the design allows (tracking-code dedup, the backfill migration,
     * the studio's cross-shop views). Restores the prior bypass state even on throw.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withoutTenancy(callable $callback): mixed
    {
        $previous = $this->bypass;
        $this->bypass = true;

        try {
            return $callback();
        } finally {
            $this->bypass = $previous;
        }
    }
}

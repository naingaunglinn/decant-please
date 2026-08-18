<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * A storefront host → shop mapping (ADR-0004). Platform-owned like Shop itself:
 * deliberately NO BelongsToShop — this table is what turns a Host header into a
 * tenant, so it is read before any tenant context can exist.
 *
 * `host` is stored normalized (lowercase, scheme/path/trailing-dot stripped, port
 * kept — dev is `localhost:3001`). A row resolves publicly only when `verified_at`
 * is set AND its shop is active; everything else is the same generic 404 as an
 * unknown host. `www.x.com` is its own row, secondary to `x.com` — normalization
 * never guesses at aliases.
 */
#[Fillable(['shop_id', 'host', 'is_primary', 'verified_at'])]
class ShopDomain extends Model
{
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $domain): void {
            $domain->host = self::normalizeHost($domain->host);
        });

        // The CORS allowlist derives from this table (AppServiceProvider). Bust on
        // every write; bulk queries fire no model events (the v12 lesson), so any
        // bulk write path must call forgetCorsOrigins() itself.
        static::saved(fn () => self::forgetCorsOrigins());
        static::deleted(fn () => self::forgetCorsOrigins());
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** The one rule for turning stored or user input into the canonical host form. */
    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host); // pasted scheme
        $host = explode('/', $host, 2)[0];                                   // pasted path

        return rtrim($host, '.');                                            // FQDN trailing dot
    }

    /** The scheme rule lives in one place: local hosts are plain http, real ones https. */
    public static function originFor(string $host): string
    {
        $isLocal = str_starts_with($host, 'localhost')
            || str_starts_with($host, '127.0.0.1')
            || str_contains($host, '.localhost');

        return ($isLocal ? 'http' : 'https')."://{$host}";
    }

    /**
     * Origins for the CORS allowlist: every verified domain of every active shop.
     * Cached 60s under a platform-level key (this table is not tenant-scoped, so
     * no shop belongs in the key) and busted by the model hooks above.
     */
    public static function corsOrigins(): array
    {
        return Cache::remember('cors.shop-origins', 60, fn (): array => static::query()
            ->whereNotNull('verified_at')
            ->whereHas('shop', fn (Builder $query) => $query->active())
            ->orderBy('host')
            ->pluck('host')
            ->map(fn (string $host): string => self::originFor($host))
            ->all());
    }

    public static function forgetCorsOrigins(): void
    {
        Cache::forget('cors.shop-origins');
    }
}

<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Shop;
use Illuminate\Support\Collection;

/**
 * Step 34 PR-3 — the cross-shop numbers the Studio registry shows per shop
 * (orders total / this month / last activity) and the payment-completeness leg of
 * "needs attention". Kept in one place so the ONE budgeted cross-shop read is a
 * single, documented call site.
 *
 * Callers compute each value ONCE per table render (ShopsTable::configure captures
 * the result), so there is no per-request memoisation to leak across tests.
 */
class StudioShopStats
{
    /**
     * The payment fields that count toward "payment configured" — the ShopConfig
     * keys the /meta payment block resolves (D2). QR path is storage-only, not a
     * ShopConfig value, so it is deliberately not here.
     */
    public const PAYMENT_KEYS = [
        'payment.kbzpay_name',
        'payment.kbzpay_number',
        'payment.wave_name',
        'payment.wave_number',
        'payment.instructions',
    ];

    /**
     * Per-shop order aggregates keyed by shop_id: total, this_month, last_at.
     *
     * The single budgeted cross-shop read for the Studio registry (design §8 ledger:
     * "the studio's cross-shop views (step 34)"). Order is BelongsToShop and the
     * registry runs with no tenant set, so the read goes through the metered
     * TenantContext::withoutTenancy() below — the one new bypass call site in app/,
     * keeping the ≤5 ledger cap intact (2 total, with Order's tracking dedup). It is
     * correlated by shop_id
     * (GROUP BY shop_id), so one shop's rows never enter another's totals — no
     * tenant visibility is widened. Portable SUM(CASE …) — no Postgres-only syntax.
     */
    public static function orderStats(): Collection
    {
        return app(TenantContext::class)->withoutTenancy(
            fn (): Collection => Order::query()
                ->selectRaw('shop_id')
                ->selectRaw('count(*) as total')
                ->selectRaw('sum(case when created_at >= ? then 1 else 0 end) as this_month', [now()->startOfMonth()])
                ->selectRaw('max(created_at) as last_at')
                ->groupBy('shop_id')
                ->get()
                ->keyBy('shop_id'),
        );
    }

    /** This shop's row from orderStats(), or a zero row when it has no orders. */
    public static function forShop(int $shopId, Collection $stats): object
    {
        return $stats->get($shopId) ?? (object) ['total' => 0, 'this_month' => 0, 'last_at' => null];
    }

    /**
     * Shop ids whose payment configuration is incomplete — no payment field resolves
     * through ShopConfig (D1's config-incomplete leg; D2's payment rollup). Uses the
     * blessed ShopConfig::forShop (shop → env → off), so it is NOT a withoutTenancy
     * bypass and does not reproduce ShopConfig's resolution. Shop is a platform table,
     * so listing ids applies no tenant scope.
     *
     * @return array<int, int>
     */
    public static function paymentIncompleteShopIds(): array
    {
        return Shop::query()
            ->get(['id'])
            ->reject(fn (Shop $shop): bool => self::paymentConfigured($shop))
            ->pluck('id')
            ->all();
    }

    /** Payment is configured when at least one payment field resolves for the shop. */
    public static function paymentConfigured(Shop $shop): bool
    {
        foreach (self::PAYMENT_KEYS as $key) {
            if (ShopConfig::forShop($shop, $key) !== null) {
                return true;
            }
        }

        return false;
    }
}

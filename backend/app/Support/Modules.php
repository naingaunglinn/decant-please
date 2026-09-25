<?php

namespace App\Support;

use App\Models\ShopSetting;
use App\Templates\Templates;

/**
 * Optional features a shop turns on or off (step 41; AGENTS.md P3 — a shop that
 * doesn't use one sees nothing of it). The keys are stored in
 * shop_settings.modules, so never rename one. Null there means "the shop
 * template's defaults" (Template::defaultModules()); the admin's Features page
 * stores the full enabled set.
 *
 * A module hides screens, never writes: with stock off, draw-down still runs on
 * any counted product; with cost off, each order line still freezes its cost.
 * Turning a module back on shows true numbers. Delivery zones are not a module
 * yet — checkout needs a township (no free-text fallback), so turning them off
 * would break the order path.
 */
final class Modules
{
    public const PRODUCTION_SCHEDULE = 'production_schedule';

    public const STOCK = 'stock';

    public const COST_MARGIN = 'cost_margin';

    public const PROMO_CODES = 'promo_codes';

    public const EXPENSES = 'expenses';

    /** @return array<string, array{label: string, help: string}> every module, in Features-page order */
    public static function all(): array
    {
        return [
            self::STOCK => [
                'label' => 'Stock',
                'help' => 'Count what you have left and get a reorder warning.',
            ],
            self::COST_MARGIN => [
                'label' => 'Cost & margin',
                'help' => 'Record what you pay for your goods and see your margin on each order.',
            ],
            self::PRODUCTION_SCHEDULE => [
                'label' => 'Production schedule',
                'help' => 'A calendar of what to prepare each day.',
            ],
            self::PROMO_CODES => [
                'label' => 'Promo codes',
                'help' => 'Discount codes customers type at checkout.',
            ],
            self::EXPENSES => [
                'label' => 'Expenses & profit',
                'help' => 'Record shop expenses and see a monthly profit & loss.',
            ],
        ];
    }

    /** Whether the current shop has $module on. */
    public static function on(string $module): bool
    {
        return in_array($module, self::enabled(), true);
    }

    /**
     * The current shop's enabled modules, in all() order. Memoised per shop id
     * for the request — the nav asks once per menu item — and forgotten when the
     * settings row saves (ShopSetting::booted), so a toggle shows at once.
     * No tenant (a console command): every module, as before step 41.
     *
     * @return list<string>
     */
    public static function enabled(): array
    {
        $shop = app(TenantContext::class)->get();

        if ($shop === null) {
            return array_keys(self::all());
        }

        return self::enabledFor($shop->id);
    }

    /** @return list<string> */
    private static function enabledFor(int $shopId): array
    {
        // once() keys on the captured variables — $shopId gives each shop its own entry.
        return once(function () use ($shopId): array {
            unset($shopId);

            // currentOrNull, never current(): a read must not create the settings row.
            $settings = ShopSetting::currentOrNull();
            $stored = $settings?->modules
                ?? Templates::get($settings?->template ?? Templates::DEFAULT)->defaultModules();

            return self::known($stored);
        });
    }

    /**
     * Keep the known keys only, in all() order — a stored set may carry a key a
     * later release retired, and a template list is written by hand.
     *
     * @param  array<int, string>  $modules
     * @return list<string>
     */
    public static function known(array $modules): array
    {
        return array_values(array_filter(array_keys(self::all()), fn (string $key): bool => in_array($key, $modules, true)));
    }
}

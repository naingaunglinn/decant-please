<?php

namespace Tests;

use App\Enums\Courier;
use App\Models\DeliveryTownship;
use App\Models\Shop;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test runs single-shop, mirroring production's interim resolver
     * (SetDefaultTenant): once BelongsToShop's scope throws without a tenant, an
     * unscoped test create would error, so pin the default shop here. Tests that
     * exercise isolation set a different shop on TenantContext directly.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('shops')) {
            $shop = Shop::firstOrCreate(
                ['slug' => config('app.shop_slug')],
                ['name' => 'Test Shop', 'is_active' => true],
            );

            app(TenantContext::class)->set($shop);

            // Under Filament tenancy (Step 25a) every panel route carries {tenant}
            // (/admin/{shop}/…). A test that renders a panel page generates those URLs,
            // so the param needs a value — register it as a URL default. Data scoping is
            // handled separately by TenantContext above; this is URL generation only,
            // and avoids Filament::setTenant() (which fires TenantSet with a null user
            // when no one is authenticated).
            URL::defaults(['tenant' => $shop->slug]);
        }
    }

    /**
     * An active, courier-served township — the gate every checkout must pass
     * since step 30. Fee defaults to 0 (a real free-delivery zone) so existing
     * total assertions stay untouched; fee-math tests pass their own.
     */
    protected function serviceableTownship(int $fee = 0, string $name = 'Bahan', ?string $nameMm = null): DeliveryTownship
    {
        $township = DeliveryTownship::firstOrCreate(
            ['region' => 'yangon', 'name' => $name],
            ['name_mm' => $nameMm, 'fee_mmk' => $fee, 'is_active' => true],
        );

        $township->couriers()->firstOrCreate(
            ['courier' => Courier::RoyalExpress->value],
            ['courier_name' => $name, 'is_available' => true],
        );

        return $township;
    }
}

<?php

namespace Tests;

use App\Enums\Courier;
use App\Enums\ShopStatus;
use App\Models\DeliveryTownship;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test runs single-shop by default: once BelongsToShop's scope throws
     * without a tenant, an unscoped test create would error, so pin the default
     * shop here. Tests that exercise isolation set a different shop on
     * TenantContext directly — and the route-resolution regression tests clear
     * this preset on purpose, so a request must resolve its own tenant.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // spatie/permission caches role+permission IDs; RefreshDatabase re-creates
        // them with new IDs each test, so a carried-over cache makes hasRole()/can()
        // (and Shield's super_admin Gate::before) silently miss. Forget it per test.
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('shops')) {
            $shop = Shop::firstOrCreate(
                ['slug' => config('app.shop_slug')],
                ['name' => 'Test Shop', 'status' => ShopStatus::Live],
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
     * The operator account panel tests authenticate as. Under Filament tenancy
     * (Step 25a) every /admin/{tenant} request passes IdentifyTenant, which 404s
     * any user whose canAccessTenant() fails — so a test admin must be a studio
     * account, exactly like the backfilled production founder.
     */
    protected function studioUser(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@decantplease.local',
            'password' => 'secret-password',
            'is_studio' => true,
        ]);

        // Step 34: /studio access + resource authorization is the studio_admin role
        // (Shield super_admin). The role is created by the migration; assign it so
        // panel tests authenticate as an authorized operator under deny-by-default.
        $user->assignRole('studio_admin');

        return $user;
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

<?php

namespace Database\Seeders;

use App\Enums\ShopStatus;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $password = env('ADMIN_PASSWORD');

        if (! $password) {
            throw new RuntimeException('ADMIN_PASSWORD is not set in .env — refusing to seed the admin user without one.');
        }

        $admin = User::updateOrCreate(
            ['email' => 'admin@decantplease.local'],
            ['name' => 'Admin', 'password' => Hash::make($password), 'is_studio' => true],
        );
        // Step 34: the studio operator's account holds studio_admin (Shield
        // super_admin). Idempotent; the role is created by the migration. Guarded
        // so a partial install (no roles table yet) can't break the seeder.
        if (Schema::hasTable('roles')) {
            $admin->assignRole('studio_admin');
        }

        // Pin the default shop before any tenant-owned seeding — the child seeders
        // (catalog, zones, orders, promos) all create scoped rows and the creating
        // hook fills shop_id from this context (multi-tenancy Step 23 §7).
        $shop = Shop::firstOrCreate(
            ['slug' => config('app.shop_slug')],
            ['name' => config('app.name'), 'status' => ShopStatus::Live],
        );
        app(TenantContext::class)->set($shop);

        // ADR-0004: the shared storefront resolves hosts through shop_domains. Map
        // the fixed local port to the default shop so the storefront, curl, and
        // every verify-*.mjs script resolve with no env var and no DNS. Idempotent.
        $shop->domains()->updateOrCreate(
            ['host' => 'localhost:3001'],
            ['is_primary' => true, 'verified_at' => now()],
        );

        $this->call([
            CatalogSeeder::class,
            DeliveryZoneSeeder::class, // before orders — demo checkouts pick a township
            OrderSeeder::class,
            PromoCodeSeeder::class,
        ]);
    }
}

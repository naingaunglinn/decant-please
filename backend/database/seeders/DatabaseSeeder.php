<?php

namespace Database\Seeders;

use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
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

        User::updateOrCreate(
            ['email' => 'admin@decantplease.local'],
            ['name' => 'Admin', 'password' => Hash::make($password), 'is_studio' => true],
        );

        // Pin the default shop before any tenant-owned seeding — the child seeders
        // (catalog, zones, orders, promos) all create scoped rows and the creating
        // hook fills shop_id from this context (multi-tenancy Step 23 §7).
        $shop = Shop::firstOrCreate(
            ['slug' => config('app.shop_slug')],
            ['name' => config('app.name'), 'is_active' => true],
        );
        app(TenantContext::class)->set($shop);

        $this->call([
            CatalogSeeder::class,
            DeliveryZoneSeeder::class, // before orders — demo checkouts pick a township
            OrderSeeder::class,
            PromoCodeSeeder::class,
        ]);
    }
}

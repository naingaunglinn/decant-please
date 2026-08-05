<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\DeliveryTownship;
use App\Models\DeliveryTownshipCourier;
use App\Models\Fragrance;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreshStartTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_start_wipes_catalog_and_orders_but_keeps_brands_and_users(): void
    {
        $this->seed();

        $this->assertGreaterThan(0, Order::count());
        $brandCount = Brand::count();
        $townshipCount = DeliveryTownship::count();
        $courierRowCount = DeliveryTownshipCourier::count();
        // the demo seed activates Yangon at a placeholder fee — the exact thing
        // fresh-start must not let survive into a real shop
        $this->assertGreaterThan(0, DeliveryTownship::where('is_active', true)->count());

        $this->artisan('decant:fresh-start', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Fragrance::count());
        $this->assertSame(0, PromoCode::count());
        $this->assertSame($brandCount, Brand::count());
        $this->assertSame(1, User::count());

        // zones are configuration: geography + courier coverage survive, but
        // every row resets to inactive at 0 — no invented fee goes live
        $this->assertSame($townshipCount, DeliveryTownship::count());
        $this->assertSame($courierRowCount, DeliveryTownshipCourier::count());
        $this->assertSame(0, DeliveryTownship::where('is_active', true)->count());
        $this->assertSame(0, DeliveryTownship::where('fee_mmk', '>', 0)->count());
    }

    public function test_fresh_start_aborts_without_confirmation(): void
    {
        $this->seed();
        $orders = Order::count();

        $this->artisan('decant:fresh-start')
            ->expectsConfirmation(
                'This permanently deletes ALL orders (with their payment proofs), ALL fragrances (with their prices and images) and ALL promo codes. Brands, the admin login and the delivery-zone list are kept — but every zone is reset to inactive with no fee, so the demo placeholder can\'t go live. Continue?',
                'no'
            )
            ->assertSuccessful();

        $this->assertSame($orders, Order::count());
    }
}

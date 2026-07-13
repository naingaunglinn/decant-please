<?php

namespace Tests\Feature;

use App\Models\Brand;
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

        $this->artisan('decant:fresh-start', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Fragrance::count());
        $this->assertSame(0, PromoCode::count());
        $this->assertSame($brandCount, Brand::count());
        $this->assertSame(1, User::count());
    }

    public function test_fresh_start_aborts_without_confirmation(): void
    {
        $this->seed();
        $orders = Order::count();

        $this->artisan('decant:fresh-start')
            ->expectsConfirmation(
                'This permanently deletes ALL orders, ALL fragrances (with their prices and images) and ALL promo codes. Brands and the admin login are kept. Continue?',
                'no'
            )
            ->assertSuccessful();

        $this->assertSame($orders, Order::count());
    }
}

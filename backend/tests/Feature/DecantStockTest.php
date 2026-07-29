<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Widgets\LowStock;
use App\Models\Brand;
use App\Models\Fragrance;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DecantStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'admin@decantplease.local',
            'password' => 'secret-password',
        ]));
    }

    public function test_stock_drops_when_an_order_is_decanted(): void
    {
        $fragrance = $this->trackedFragrance(stockMl: 100);
        $order = $this->checkout($fragrance, sizeMl: 10, quantity: 2);

        // Nothing is drawn down while the order is only accepted (pending).
        $order->accept(today()->addDay(), today()->addDays(2));
        $this->assertSame(100, $fragrance->refresh()->stock_ml);

        // The pour happens on the → Decanted transition: 10ml × 2 = 20ml.
        $order->update(['status' => OrderStatus::Decanted]);
        $this->assertSame(80, $fragrance->refresh()->stock_ml);
    }

    public function test_multiple_sizes_of_the_same_fragrance_draw_from_one_total(): void
    {
        $fragrance = $this->trackedFragrance(stockMl: 100);
        $fragrance->decantPrices()->create(['size_ml' => 5, 'price_mmk' => 50000, 'in_stock' => true]);

        $order = Order::newFromCheckout([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'items' => [
                ['fragrance_id' => $fragrance->id, 'size_ml' => 10, 'quantity' => 1],
                ['fragrance_id' => $fragrance->id, 'size_ml' => 5, 'quantity' => 1],
            ],
        ]);

        $order->update(['status' => OrderStatus::Decanted]);

        // 10 + 5 = 15ml off the single running total, in one write.
        $this->assertSame(85, $fragrance->refresh()->stock_ml);
    }

    public function test_over_draw_is_warn_only_clamped_at_zero_and_never_blocks(): void
    {
        $fragrance = $this->trackedFragrance(stockMl: 15);
        $order = $this->checkout($fragrance, sizeMl: 10, quantity: 2); // needs 20ml, only 15 left

        $order->update(['status' => OrderStatus::Decanted]);

        $fragrance->refresh();
        $this->assertSame(0, $fragrance->stock_ml);                       // clamped, not -5
        $this->assertTrue($fragrance->decantPrices->first()->in_stock);   // manual toggle untouched
        $this->assertSame(OrderStatus::Decanted, $order->refresh()->status); // transition not blocked
    }

    public function test_untracked_fragrance_is_left_alone(): void
    {
        $fragrance = $this->trackedFragrance(stockMl: null); // not tracked
        $order = $this->checkout($fragrance, sizeMl: 10, quantity: 1);

        $order->update(['status' => OrderStatus::Decanted]); // must not error

        $this->assertNull($fragrance->refresh()->stock_ml);
    }

    public function test_add_bottle_tops_up_the_running_total(): void
    {
        $fragrance = $this->trackedFragrance(stockMl: null);

        $fragrance->addBottle(100); // starts tracking a previously-untracked fragrance
        $this->assertSame(100, $fragrance->refresh()->stock_ml);

        $fragrance->addBottle(50);
        $this->assertSame(150, $fragrance->refresh()->stock_ml);
    }

    public function test_low_stock_widget_lists_only_tracked_fragrances_at_or_below_threshold(): void
    {
        $low = $this->trackedFragrance(stockMl: 20, threshold: 30, brand: 'Creed', name: 'Aventus');
        $healthy = $this->trackedFragrance(stockMl: 100, threshold: 30, brand: 'Dior', name: 'Sauvage');
        $untracked = $this->trackedFragrance(stockMl: null, brand: 'Chanel', name: 'Bleu');

        Livewire::test(LowStock::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$low])
            ->assertCanNotSeeTableRecords([$healthy, $untracked]);
    }

    private function trackedFragrance(
        ?int $stockMl,
        int $threshold = 30,
        string $brand = 'Creed',
        string $name = 'Aventus',
    ): Fragrance {
        $brandModel = Brand::create(['name' => $brand, 'type' => 'niche']);

        $fragrance = $brandModel->fragrances()->create([
            'name' => $name,
            'concentration' => 'edp',
            'gender' => 'male',
            'stock_ml' => $stockMl,
            'low_stock_threshold_ml' => $threshold,
        ]);

        $fragrance->decantPrices()->create(['size_ml' => 10, 'price_mmk' => 90000, 'in_stock' => true]);

        return $fragrance;
    }

    private function checkout(Fragrance $fragrance, int $sizeMl, int $quantity): Order
    {
        return Order::newFromCheckout([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'items' => [[
                'fragrance_id' => $fragrance->id,
                'size_ml' => $sizeMl,
                'quantity' => $quantity,
            ]],
        ]);
    }
}

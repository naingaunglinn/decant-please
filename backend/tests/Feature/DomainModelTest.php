<?php

namespace Tests\Feature;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Brand;
use App\Models\DecantPrice;
use App\Models\Fragrance;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class DomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_decant_prices_sort_by_size_and_min_price_ignores_out_of_stock(): void
    {
        $fragrance = $this->makeFragrance();
        $fragrance->decantPrices()->createMany([
            ['size_ml' => 30, 'price_mmk' => 150000],
            ['size_ml' => 5, 'price_mmk' => 30000, 'in_stock' => false],
            ['size_ml' => 10, 'price_mmk' => 55000],
        ]);

        $this->assertSame([5, 10, 30], $fragrance->decantPrices()->pluck('size_ml')->all());
        $this->assertSame(55000, $fragrance->minPrice());
        $this->assertSame('chanel-allure-homme-sport', $fragrance->slug);
    }

    public function test_recalculate_total_sums_items_plus_fee_minus_discount_floored_at_zero(): void
    {
        $fragrance = $this->makeFragrance();
        $order = $this->makeOrder(['delivery_fee_mmk' => 3000, 'discount_mmk' => 5000]);
        $order->items()->create([
            'fragrance_id' => $fragrance->id,
            'fragrance_name_snapshot' => 'Chanel Allure Homme Sport',
            'size_ml' => 5,
            'unit_price_mmk' => 25000,
            'quantity' => 2,
        ]);
        $order->items()->create([
            'fragrance_id' => $fragrance->id,
            'fragrance_name_snapshot' => 'Chanel Allure Homme Sport',
            'size_ml' => 10,
            'unit_price_mmk' => 40000,
            'quantity' => 1,
        ]);

        $order->recalculateTotal();
        $this->assertSame(88000, $order->fresh()->total_mmk); // 50,000 + 40,000 + 3,000 - 5,000

        $order->discount_mmk = 999999;
        $order->recalculateTotal();
        $this->assertSame(0, $order->fresh()->total_mmk);
    }

    public function test_tracking_code_is_generated_unique_and_unambiguous(): void
    {
        $first = $this->makeOrder();
        $second = $this->makeOrder();

        // 10 chars, no 0/O/1/I
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{10}$/', $first->tracking_code);
        $this->assertNotSame($first->tracking_code, $second->tracking_code);

        $explicit = $this->makeOrder(['tracking_code' => 'MYCODE2345']);
        $this->assertSame('MYCODE2345', $explicit->tracking_code);
    }

    public function test_accept_sets_pending_and_both_dates(): void
    {
        $order = $this->makeOrder();
        $order->accept(Carbon::parse('2026-07-20'), Carbon::parse('2026-07-22'));

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame('2026-07-20', $order->decant_date->toDateString());
        $this->assertSame('2026-07-22', $order->delivery_date->toDateString());

        $this->expectException(LogicException::class);
        $order->accept(Carbon::parse('2026-07-21'));
    }

    public function test_reject_sets_reason_and_leaves_decant_date_null(): void
    {
        $order = $this->makeOrder();
        $order->reject('Bottle ran out.');

        $order->refresh();
        $this->assertSame(OrderStatus::Rejected, $order->status);
        $this->assertSame('Bottle ran out.', $order->rejection_reason);
        $this->assertNull($order->decant_date);
    }

    public function test_checkout_creates_awaiting_order_with_server_derived_prices(): void
    {
        $price = $this->makePrice();

        $order = Order::newFromCheckout([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'items' => [[
                'fragrance_id' => $price->fragrance_id,
                'size_ml' => 10,
                'quantity' => 2,
                'unit_price_mmk' => 1, // client-supplied price must be ignored
            ]],
        ]);

        $order->refresh()->load('items');
        $this->assertSame(OrderStatus::AwaitingConfirmation, $order->status);
        $this->assertSame(OrderSource::Website, $order->order_from);
        $this->assertNull($order->decant_date);
        $this->assertNotNull($order->tracking_code);
        $this->assertSame(55000, $order->items[0]->unit_price_mmk);
        $this->assertSame(110000, $order->items[0]->line_total_mmk);
        $this->assertSame(110000, $order->total_mmk);
        $this->assertSame('Chanel Allure Homme Sport', $order->items[0]->fragrance_name_snapshot);
    }

    public function test_checkout_rejects_unavailable_items_and_rolls_everything_back(): void
    {
        $good = $this->makePrice();
        $outOfStock = $good->fragrance->decantPrices()->create([
            'size_ml' => 30, 'price_mmk' => 150000, 'in_stock' => false,
        ]);

        $base = [
            'customer_name' => 'Su Su', 'phone' => '09-9', 'address' => 'Yangon',
        ];

        // out-of-stock size
        try {
            Order::newFromCheckout($base + ['items' => [
                ['fragrance_id' => $good->fragrance_id, 'size_ml' => 10, 'quantity' => 1],
                ['fragrance_id' => $outOfStock->fragrance_id, 'size_ml' => 30, 'quantity' => 1],
            ]]);
            $this->fail('Expected ValidationException for out-of-stock item.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.1', $e->errors());
        }

        // nothing half-created
        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());

        // inactive fragrance
        $good->fragrance->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        Order::newFromCheckout($base + ['items' => [
            ['fragrance_id' => $good->fragrance_id, 'size_ml' => 10, 'quantity' => 1],
        ]]);
    }

    public function test_money_formats_whole_kyat(): void
    {
        $this->assertSame('90,000 Ks', Money::kyat(90000));
        $this->assertSame('0 Ks', Money::kyat(0));
    }

    private function makeFragrance(): Fragrance
    {
        $brand = Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        return Fragrance::create([
            'brand_id' => $brand->id,
            'name' => 'Allure Homme Sport',
            'concentration' => 'cologne',
            'gender' => 'male',
        ]);
    }

    private function makePrice(): DecantPrice
    {
        return $this->makeFragrance()->decantPrices()->create([
            'size_ml' => 10,
            'price_mmk' => 55000,
        ]);
    }

    private function makeOrder(array $overrides = []): Order
    {
        return Order::create($overrides + [
            'customer_name' => 'Test Customer',
            'phone' => '09-700000000',
            'address' => 'Yangon',
        ])->refresh();
    }
}

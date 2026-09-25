<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Brand;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Shop;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Issue #112: a shop that holds money records (orders, order lines, expenses) cannot
 * be hard-deleted — the database refuses, not just policy. Catalog/config still
 * cascades, so a shop with no financial history deletes cleanly.
 */
class ShopDeleteProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_tables_restrict_shop_delete(): void
    {
        foreach (['orders', 'order_items', 'expenses'] as $table) {
            $fk = collect(Schema::getForeignKeys($table))
                ->first(fn (array $fk) => $fk['columns'] === ['shop_id']);

            $this->assertNotNull($fk, "{$table}.shop_id has no FK");
            $this->assertSame('restrict', strtolower($fk['on_delete']), "{$table}.shop_id must restrict");
        }
    }

    public function test_shop_with_an_order_cannot_be_deleted(): void
    {
        $shop = $this->otherShop();
        $order = $this->orderIn($shop);
        $order->items()->create([
            'fragrance_id' => $this->itemFragrance()->id,
            'fragrance_name_snapshot' => 'Fixture Brand Fixture',
            'size_ml' => 10,
            'unit_price_mmk' => 10000,
            'quantity' => 1,
        ]);

        $this->assertDeleteRefused($shop);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total_mmk' => 10000]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'unit_price_mmk' => 10000]);
    }

    public function test_shop_with_only_an_expense_cannot_be_deleted(): void
    {
        $shop = $this->otherShop();
        app(TenantContext::class)->set($shop);
        $expense = Expense::create(['spent_on' => '2026-09-01', 'category' => 'other', 'amount_mmk' => 5000]);

        $this->assertDeleteRefused($shop);

        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'amount_mmk' => 5000]);
    }

    public function test_shop_without_money_records_still_deletes_and_cascades_its_catalog(): void
    {
        $shop = $this->otherShop();
        app(TenantContext::class)->set($shop);
        $brand = Brand::create(['name' => 'Cascade Brand', 'type' => 'designer']);

        $shop->delete();

        $this->assertDatabaseMissing('shops', ['id' => $shop->id]);
        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
    }

    private function otherShop(): Shop
    {
        return Shop::factory()->create(['slug' => 'money-shop', 'name' => 'Money Shop']);
    }

    private function orderIn(Shop $shop): Order
    {
        app(TenantContext::class)->set($shop);

        return Order::create([
            'customer_name' => 'Buyer',
            'phone' => '09-771234561',
            'address' => 'Somewhere, Yangon',
            'order_from' => 'website',
            'status' => OrderStatus::Pending,
            'total_mmk' => 10000,
        ]);
    }

    private function assertDeleteRefused(Shop $shop): void
    {
        try {
            // own transaction → a savepoint, so the failed statement doesn't poison
            // the RefreshDatabase transaction on Postgres
            DB::transaction(fn () => $shop->delete());
            $this->fail('A shop holding money records was deleted.');
        } catch (QueryException) {
            // refused by the FK — expected
        }

        $this->assertDatabaseHas('shops', ['id' => $shop->id]);
    }
}

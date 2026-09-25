<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ShopStatus;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Widgets\LowStock;
use App\Models\Brand;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ShopSetting;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Step 40: two stock modes, chosen by the product's template. Pooled (decant) is
 * DecantStockTest's ground and must not move; this covers per variant (clothing) —
 * draw-down, the cost snapshot, shortfalls, the low-stock panel — an order mixing
 * both, the admin form, and the migration on seeded rows in two shops.
 */
class StockModesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ShopSetting::current()->update(['template' => 'clothing']);
    }

    /** @param  array<array{0: string, 1: ?int, 2?: ?int}>  $variants  label "M / Blue", stock_qty, unit_cost_mmk */
    private function shirt(array $variants, int $threshold = 2, string $name = 'Linen Shirt'): Product
    {
        $product = Product::create([
            'name' => $name, 'template' => 'clothing', 'low_stock_threshold' => $threshold,
            'attributes' => ['material' => 'linen', 'gender' => 'unisex'],
        ]);

        foreach ($variants as $position => [$label, $stock]) {
            [$size, $color] = explode(' / ', $label);
            $product->variants()->create([
                'options' => ['Size' => $size, 'Color' => $color], 'price_mmk' => 25000,
                'position' => $position, 'stock_qty' => $stock, 'unit_cost_mmk' => $variants[$position][2] ?? null,
            ]);
        }

        return $product->load('variants');
    }

    private function decant(?int $stockMl = 100): Product
    {
        $brand = Brand::firstOrCreate(['name' => 'Creed'], ['type' => 'niche']);
        $product = $brand->products()->create([
            'name' => 'Aventus', 'template' => 'decant', 'stock_amount' => $stockMl,
            'attributes' => ['concentration' => 'edp', 'gender' => 'male'],
            'reference_cost_mmk' => 100000, 'reference_amount' => 30,
        ]);
        // A stray variant cost on a pooled product must never become its COGS.
        $product->variants()->create(['size_ml' => 5, 'price_mmk' => 30000, 'unit_cost_mmk' => 1]);

        return $product;
    }

    private function variant(Product $product, string $label): ProductVariant
    {
        return $product->variants()->get()->first(fn (ProductVariant $variant) => $variant->label() === $label);
    }

    /** @param  array<array{0: ProductVariant, 1: int}>  $lines */
    private function order(array $lines): Order
    {
        $order = Order::create([
            'customer_name' => 'Su Su', 'phone' => '09-771234561', 'address' => 'Yangon',
            'order_from' => 'tiktok', 'status' => OrderStatus::Pending,
        ]);

        foreach ($lines as [$variant, $quantity]) {
            $variant->loadMissing('product');
            $order->items()->create([
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'fragrance_name_snapshot' => $variant->product->name,
                'size_ml' => $variant->size_ml,
                'unit_price_mmk' => $variant->price_mmk,
                'quantity' => $quantity,
            ]);
        }

        return $order;
    }

    // ---- draw-down ----

    public function test_a_per_variant_order_draws_each_variant_by_quantity_once(): void
    {
        $shirt = $this->shirt([['M / Blue', 5], ['L / Blue', 4], ['M / Red', null]]);
        $order = $this->order([[$this->variant($shirt, 'M / Blue'), 2], [$this->variant($shirt, 'M / Red'), 1]]);

        $order->update(['status' => OrderStatus::Prepared]);
        $order->update(['notes' => 're-saved']); // no second draw

        $this->assertSame(3, $this->variant($shirt, 'M / Blue')->stock_qty);
        $this->assertSame(4, $this->variant($shirt, 'L / Blue')->stock_qty); // not on the order
        $this->assertNull($this->variant($shirt, 'M / Red')->stock_qty);   // untracked stays untracked
        $this->assertNull($shirt->fresh()->stock_amount);
    }

    public function test_a_per_variant_over_draw_clamps_at_zero_and_never_blocks(): void
    {
        $shirt = $this->shirt([['M / Blue', 1]]);
        $order = $this->order([[$this->variant($shirt, 'M / Blue'), 3]]);

        $order->update(['status' => OrderStatus::Prepared]);

        $this->assertSame(OrderStatus::Prepared, $order->fresh()->status);
        $this->assertSame(0, $this->variant($shirt, 'M / Blue')->stock_qty);
    }

    public function test_one_order_mixing_modes_draws_each_its_own_way(): void
    {
        $shirt = $this->shirt([['M / Blue', 5]]);
        $aventus = $this->decant(100);
        $order = $this->order([[$this->variant($shirt, 'M / Blue'), 2], [$aventus->variants()->first(), 3]]);

        $order->update(['status' => OrderStatus::Prepared]);

        $this->assertSame(3, $this->variant($shirt, 'M / Blue')->stock_qty);
        $this->assertSame(85, $aventus->fresh()->stock_amount); // 3 × 5ml
        $this->assertNull($aventus->variants()->first()->stock_qty); // pooled never counts pieces
    }

    public function test_the_draw_down_locks_every_row_it_writes_on_postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SQLite has no row locks; this runs in the Postgres suite.');
        }

        $shirt = $this->shirt([['M / Blue', 5]]);
        $aventus = $this->decant(100);
        $order = $this->order([[$this->variant($shirt, 'M / Blue'), 1], [$aventus->variants()->first(), 1]]);

        DB::enableQueryLog();
        $order->update(['status' => OrderStatus::Prepared]);
        $locks = array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $sql): bool => str_contains($sql, 'for update'),
        ));

        $this->assertCount(2, $locks);
        $this->assertStringContainsString('"products"', $locks[0]);
        $this->assertStringContainsString('"product_variants"', $locks[1]);
    }

    // ---- cost ----

    public function test_a_per_variant_line_snapshots_the_variants_own_cost_and_keeps_it(): void
    {
        $shirt = $this->shirt([['M / Blue', 5, 9000], ['L / Blue', 5, null]]);
        $order = $this->order([[$this->variant($shirt, 'M / Blue'), 2]]);
        $line = $order->items()->first();

        $this->assertSame(9000, $line->unit_cost_mmk);
        $this->assertSame(18000, $line->line_cost_mmk);
        $this->assertSame(50000 - 18000, $order->fresh()->liquidGrossMarginMmk());

        // Repricing the variant's cost doesn't move a placed line.
        $this->variant($shirt, 'M / Blue')->update(['unit_cost_mmk' => 12000]);
        $line->update(['quantity' => 3]);
        $this->assertSame(9000, $line->fresh()->unit_cost_mmk);
        $this->assertSame(27000, $line->fresh()->line_cost_mmk);

        // An uncosted variant is unknown, never 0 — and so is the order's margin.
        $uncosted = $this->order([[$this->variant($shirt, 'M / Blue'), 1], [$this->variant($shirt, 'L / Blue'), 1]]);
        $this->assertNull($uncosted->items()->get()->last()->unit_cost_mmk);
        $this->assertNull($uncosted->fresh()->liquidGrossMarginMmk());
    }

    public function test_a_storefront_checkout_snapshots_the_variants_cost(): void
    {
        $shirt = $this->shirt([['M / Blue', 5, 9000]]);

        $response = $this->postJson('/api/v1/decant-please/orders', [
            'customer_name' => 'Su Su',
            'phone' => '09-771234561',
            'delivery_township_id' => $this->serviceableTownship(fee: 3000)->id,
            'address_line' => 'No. 12, Baho Road',
            'items' => [['variant_id' => $this->variant($shirt, 'M / Blue')->id, 'quantity' => 2, 'unit_cost_mmk' => 1]],
        ])->assertCreated();

        $line = Order::where('tracking_code', $response->json('tracking_code'))->firstOrFail()->items()->first();
        $this->assertSame(9000, $line->unit_cost_mmk); // a client-sent cost is ignored
        $this->assertSame(18000, $line->line_cost_mmk);
    }

    public function test_a_pooled_line_still_costs_its_share_of_the_bottle_not_a_variant_cost(): void
    {
        $aventus = $this->decant();
        $order = $this->order([[$aventus->variants()->first(), 1]]);

        // ceil(100,000 × 5 / 30) — the parity figure; the stray variant cost (1) is ignored.
        $this->assertSame(16667, $order->items()->first()->unit_cost_mmk);
    }

    // ---- shortfalls and the low-stock panel ----

    public function test_accept_warns_per_variant_in_pieces(): void
    {
        $shirt = $this->shirt([['M / Blue', 1], ['L / Blue', 9]]);
        $aventus = $this->decant(8);
        $order = $this->order([
            [$this->variant($shirt, 'M / Blue'), 2],
            [$this->variant($shirt, 'L / Blue'), 2],
            [$aventus->variants()->first(), 2],
        ]);

        $this->assertSame([
            ['name' => 'Linen Shirt M / Blue', 'needed' => 2, 'available' => 1, 'unit' => ''],
            ['name' => 'Aventus', 'needed' => 10, 'available' => 8, 'unit' => 'ml'],
        ], $order->stockShortfalls());
    }

    public function test_low_stock_lists_a_product_with_a_selling_variant_at_its_line_in_this_shop_only(): void
    {
        $low = $this->shirt([['M / Blue', 1], ['L / Blue', 10]], name: 'Low Shirt');
        $healthy = $this->shirt([['M / Blue', 3]], name: 'Healthy Shirt');
        $untracked = $this->shirt([['M / Blue', null]], name: 'Untracked Shirt');
        $archived = $this->shirt([['M / Blue', 0], ['L / Blue', 8]], name: 'Archived Shirt');
        $this->variant($archived, 'M / Blue')->update(['is_active' => false]);
        $pooled = $this->decant(10); // threshold 30ml by default

        $first = app(TenantContext::class)->get();
        $other = Shop::create(['name' => 'Other', 'slug' => 'other', 'status' => ShopStatus::Live]);
        app(TenantContext::class)->set($other);
        ShopSetting::current()->update(['template' => 'clothing']);
        $theirs = $this->shirt([['M / Blue', 0]], name: 'Their Shirt');
        app(TenantContext::class)->set($first);

        // A count left from the other mode (a template switch) doesn't flag.
        $switched = $this->shirt([['M / Blue', 9]], name: 'Switched Shirt');
        $switched->update(['stock_amount' => 1]);

        $this->assertSame(['Aventus', 'Low Shirt'], Product::query()->lowStock()->orderBy('products.name')->pluck('name')->all());
        $this->assertSame(['M / Blue'], $low->fresh()->lowVariants()->map->label()->all());

        $this->actingAs($this->studioUser());
        Livewire::test(LowStock::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$pooled, $low], inOrder: true)
            ->assertCanNotSeeTableRecords([$healthy, $untracked, $archived])
            ->assertSee('M / Blue: 1')
            ->assertSee('10ml');

        $this->assertNotNull($theirs->id);
    }

    // ---- admin ----

    public function test_the_clothing_form_counts_and_costs_each_variant(): void
    {
        $this->actingAs($this->studioUser());

        Livewire::test(CreateProduct::class)
            ->assertFormFieldDoesNotExist('stock_amount')
            ->assertFormFieldVisible('low_stock_threshold')
            ->assertFormSet(['low_stock_threshold' => 2])
            ->fillForm([
                'name' => 'Linen Shirt',
                'attributes' => ['material' => 'linen', 'gender' => 'women'],
                'variants' => [
                    ['options' => ['Size' => 'M', 'Color' => 'Blue'], 'price_mmk' => 25000, 'in_stock' => true, 'is_active' => true,
                        'stock_qty' => 6, 'unit_cost_mmk' => 9000],
                    ['options' => ['Size' => 'L', 'Color' => 'Blue'], 'price_mmk' => 27000, 'in_stock' => true, 'is_active' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $variants = Product::where('name', 'Linen Shirt')->firstOrFail()->variants()->get();
        $this->assertSame([6, null], $variants->pluck('stock_qty')->all());
        $this->assertSame([9000, null], $variants->pluck('unit_cost_mmk')->all());
    }

    // ---- migration ----

    public function test_the_migration_round_trips_on_seeded_rows_and_moves_no_value(): void
    {
        $this->decant(40);
        $this->shirt([['M / Blue', null]], threshold: 30);
        $first = app(TenantContext::class)->get();
        $other = Shop::create(['name' => 'Other', 'slug' => 'other', 'status' => ShopStatus::Live]);
        app(TenantContext::class)->set($other);
        $this->decant(7);
        app(TenantContext::class)->set($first);

        $columns = ['id', 'shop_id', 'template', 'stock_amount', 'low_stock_threshold', 'reference_cost_mmk', 'reference_amount'];
        $before = DB::table('products')->orderBy('id')->get($columns)->map(fn (object $row) => (array) $row)->all();

        $migration = require database_path('migrations/2026_09_30_000000_add_stock_modes.php');

        // Down refuses while a variant carries a count or a cost the seller entered.
        $this->expectExceptionOnce(fn () => $migration->down());
        DB::table('product_variants')->update(['stock_qty' => null, 'unit_cost_mmk' => null]);

        $migration->down();
        $this->assertTrue(Schema::hasColumn('products', 'stock_ml'));
        $this->assertFalse(Schema::hasColumn('product_variants', 'stock_qty'));
        $down = DB::table('products')->orderBy('id')->get(['id', 'stock_ml', 'reference_cost_mmk'])->map(fn (object $row) => (array) $row)->all();
        $this->assertSame(array_map(fn (array $row) => ['id' => $row['id'], 'stock_ml' => $row['stock_amount'], 'reference_cost_mmk' => $row['reference_cost_mmk']], $before), $down);
        $this->assertSame([30, 30, 30], DB::table('products')->orderBy('id')->pluck('low_stock_threshold_ml')->map(fn ($v) => (int) $v)->all());

        $migration->up();

        $after = DB::table('products')->orderBy('id')->get($columns)->map(fn (object $row) => (array) $row)->all();
        // Only the clothing product's reorder line moves (ml default 30 → 2 pieces).
        $expected = array_map(fn (array $row) => $row['template'] === 'clothing' ? ['low_stock_threshold' => 2] + $row : $row, $before);
        $this->assertEquals($expected, $after);
        $this->assertSame([40, null, 7], array_column($after, 'stock_amount'));
        $this->assertSame([30, 2, 30], array_column($after, 'low_stock_threshold'));
    }

    private function expectExceptionOnce(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected the rollback to refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('clear them before rolling back step 40', $e->getMessage());
        }
    }
}

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
use App\Support\StockUnit;
use App\Support\TenantContext;
use App\Templates\Attribute;
use App\Templates\Template;
use App\Templates\Templates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Step 40b: pooled stock by weight. A test-only weighed template (the produce
 * template itself is RUN-QUEUE row 15) sells lahpet in kyatthar; a viss is 100 of
 * them, display only. Covers the unit on the product, a variant labelling itself,
 * the frozen line amount, draw-down, ceiling cost, shortfalls, low stock, the
 * unit-switch guard, the API, the admin form and the migration.
 */
class WeightUnitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Templates::register(TestWeighedTemplate::class);
        ShopSetting::current()->update(['template' => 'test-weighed']);
    }

    protected function tearDown(): void
    {
        Templates::forget('test-weighed');

        parent::tearDown();
    }

    /** 100,000 Ks for 3 viss, the stock given, packs of 25 kyatthar and 1 viss. */
    private function lahpet(?int $stock = 500, int $threshold = 50): Product
    {
        $product = Product::create([
            'name' => 'Shan Lahpet', 'attributes' => ['origin' => 'Shan'],
            'stock_amount' => $stock, 'low_stock_threshold' => $threshold,
            'reference_cost_mmk' => 100000, 'reference_amount' => 300,
        ]);
        $product->variants()->create(['measure' => 25, 'price_mmk' => 12000]);
        $product->variants()->create(['measure' => 100, 'price_mmk' => 45000]);

        return $product;
    }

    private function pack(Product $product, int $measure): ProductVariant
    {
        return $product->variants()->where('measure', $measure)->firstOrFail();
    }

    /** @param  array<array{0: ProductVariant, 1: int}>  $lines */
    private function order(array $lines): Order
    {
        $order = Order::create([
            'customer_name' => 'Su Su', 'phone' => '09-771234561', 'address' => 'Yangon',
            'order_from' => 'tiktok', 'status' => OrderStatus::Pending,
        ]);

        foreach ($lines as [$variant, $quantity]) {
            $order->items()->create([
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'fragrance_name_snapshot' => 'Shan Lahpet',
                'size_ml' => $variant->size_ml, // what every write path sends for an ml line
                'unit_price_mmk' => $variant->price_mmk,
                'quantity' => $quantity,
            ]);
        }

        return $order;
    }

    public function test_amounts_read_in_kyatthar_and_viss(): void
    {
        $this->assertSame('25 kyatthar', StockUnit::format(25, 'kyatthar'));
        $this->assertSame('1 viss', StockUnit::format(100, 'kyatthar'));
        $this->assertSame('1 viss 50 kyatthar', StockUnit::format(150, 'kyatthar'));
        $this->assertSame('0 kyatthar', StockUnit::format(0, 'kyatthar'));
        $this->assertSame('30ml', StockUnit::format(30, 'ml'));
        $this->assertSame('3', StockUnit::format(3, ''));
    }

    public function test_a_weighed_product_takes_its_unit_and_its_variants_label_themselves(): void
    {
        $lahpet = $this->lahpet();

        $this->assertSame('kyatthar', $lahpet->fresh()->stock_unit);
        $this->assertSame(['25 kyatthar', '1 viss'], $lahpet->variants()->get()->map->label()->all());
        $this->assertSame(['Weight' => '1 viss'], $this->pack($lahpet, 100)->options);
        $this->assertNull($this->pack($lahpet, 25)->size_ml);

        // decant is ml, a per-variant product has no pooled unit
        $this->assertSame('ml', Product::create(['name' => 'Aventus', 'template' => 'decant'])->stock_unit);
        $this->assertNull(Product::create(['name' => 'Tee', 'template' => 'clothing'])->stock_unit);
    }

    public function test_an_order_draws_its_frozen_weight_and_costs_it_by_ceiling(): void
    {
        $lahpet = $this->lahpet(stock: 500);
        $small = $this->pack($lahpet, 25);
        $order = $this->order([[$small, 2], [$this->pack($lahpet, 100), 1]]);

        $items = $order->items()->orderBy('id')->get();
        $this->assertSame([25, 100], $items->pluck('measure')->all());
        // 100,000 Ks / 300 kyatthar: 25 → ⌈8,333.3⌉ = 8,334; 100 → ⌈33,333.3⌉ = 33,334
        $this->assertSame([8334, 33334], $items->pluck('unit_cost_mmk')->all());
        $this->assertSame([16668, 33334], $items->pluck('line_cost_mmk')->all());

        // The seller re-weighs the pack before this order is packed: the line keeps 25.
        $small->update(['measure' => 30]);
        $this->assertSame('30 kyatthar', $small->fresh()->label());

        $order->update(['status' => OrderStatus::Prepared]);
        $order->update(['notes' => 're-saved']); // no second draw

        // 500 − (2 × 25 + 100) = 350
        $this->assertSame(350, $lahpet->fresh()->stock_amount);
        $this->assertSame('3 viss 50 kyatthar', $lahpet->fresh()->formatAmount(350));
        $this->assertSame(8334, $items[0]->fresh()->unit_cost_mmk);
    }

    public function test_a_weight_shortfall_and_low_stock_speak_in_viss(): void
    {
        $lahpet = $this->lahpet(stock: 120, threshold: 150);
        $order = $this->order([[$this->pack($lahpet, 100), 2]]);

        $this->assertSame(
            [['name' => 'Shan Lahpet', 'needed' => 200, 'available' => 120, 'unit' => 'kyatthar']],
            $order->stockShortfalls(),
        );

        $this->actingAs($this->studioUser());
        Livewire::test(LowStock::class)
            ->assertCanSeeTableRecords([$lahpet])
            ->assertSee('1 viss 20 kyatthar')
            ->assertSee('1 viss 50 kyatthar');
    }

    // ---- the unit-switch guard ----

    public function test_a_product_counted_in_ml_cannot_switch_to_weight(): void
    {
        $brand = Brand::create(['name' => 'Creed', 'type' => 'niche']);
        $aventus = $brand->products()->create([
            'name' => 'Aventus', 'template' => 'decant', 'stock_amount' => 500,
            'attributes' => ['concentration' => 'edp', 'gender' => 'male'],
        ]);

        $this->assertRefused(fn () => $aventus->update(['template' => 'test-weighed']));
        $this->assertSame(['decant', 'ml', 500], [$aventus->fresh()->template, $aventus->fresh()->stock_unit, $aventus->fresh()->stock_amount]);

        // A per-variant template keeps the unit, so going there and back loses nothing.
        $aventus->update(['template' => 'clothing']);
        $aventus->update(['template' => 'decant']);
        $this->assertSame(['ml', 500], [$aventus->fresh()->stock_unit, $aventus->fresh()->stock_amount]);

        // Cleared, it may switch — and is counted in kyatthar from then on.
        $aventus->update(['stock_amount' => null]);
        $aventus->update(['template' => 'test-weighed']);
        $this->assertSame('kyatthar', $aventus->fresh()->stock_unit);
    }

    public function test_a_product_on_an_order_cannot_switch_units_even_untracked(): void
    {
        $brand = Brand::create(['name' => 'Creed', 'type' => 'niche']);
        $aventus = $brand->products()->create([
            'name' => 'Aventus', 'template' => 'decant', 'attributes' => ['concentration' => 'edp', 'gender' => 'male'],
        ]);
        $ten = $aventus->variants()->create(['size_ml' => 10, 'price_mmk' => 40000]);
        // an accepted order that isn't packed yet: its 10ml must not draw 10 kyatthar
        $this->order([[$ten, 1]]);

        $this->assertRefused(fn () => $aventus->update(['template' => 'test-weighed']));
        $this->assertSame('ml', $aventus->fresh()->stock_unit);
    }

    // ---- API and admin ----

    public function test_the_api_serves_weights_as_options_and_checkout_freezes_the_amount(): void
    {
        $lahpet = $this->lahpet();

        $meta = $this->getJson('/api/v1/decant-please/meta')->assertOk()->json();
        $this->assertSame([['name' => 'Weight', 'values' => ['25 kyatthar', '1 viss']]], $meta['variant_options']);
        $this->assertSame([], $meta['sizes']);

        $this->assertSame(['Shan Lahpet'], array_column(
            $this->getJson('/api/v1/decant-please/products?option[Weight]=1 viss')->assertOk()->json('data'), 'name'));

        $response = $this->postJson('/api/v1/decant-please/orders', [
            'customer_name' => 'Su Su',
            'phone' => '09-771234561',
            'delivery_township_id' => $this->serviceableTownship(fee: 3000)->id,
            'address_line' => 'No. 12, Baho Road',
            'items' => [['variant_id' => $this->pack($lahpet, 100)->id, 'quantity' => 1]],
        ])->assertCreated();

        $item = Order::where('tracking_code', $response->json('tracking_code'))->firstOrFail()->items()->firstOrFail();
        $this->assertSame([100, null, '1 viss', 45000, 33334], [$item->measure, $item->size_ml, $item->variant_label_snapshot, $item->unit_price_mmk, $item->unit_cost_mmk]);
    }

    public function test_the_admin_form_takes_weights_in_kyatthar(): void
    {
        $this->actingAs($this->studioUser());

        Livewire::test(CreateProduct::class)
            ->assertFormFieldVisible('stock_amount')
            ->assertFormFieldVisible('reference_amount')
            ->assertFormFieldDoesNotExist('variants.0.size_ml')
            ->fillForm([
                'name' => 'Dried Fish',
                'attributes' => ['origin' => 'Myeik'],
                'stock_amount' => 250,
                'reference_cost_mmk' => 60000, 'reference_amount' => 100,
                'variants' => [
                    ['measure' => 50, 'price_mmk' => 35000, 'in_stock' => true, 'is_active' => true],
                    ['measure' => 100, 'price_mmk' => 65000, 'in_stock' => true, 'is_active' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $fish = Product::where('name', 'Dried Fish')->firstOrFail();
        $this->assertSame(['kyatthar', 250], [$fish->stock_unit, $fish->stock_amount]);
        $this->assertSame(['50 kyatthar', '1 viss'], $fish->variants()->get()->map->label()->all());
    }

    // ---- migration ----

    public function test_the_migration_backfills_ml_and_refuses_to_roll_back_weights(): void
    {
        $brand = Brand::create(['name' => 'Creed', 'type' => 'niche']);
        $aventus = $brand->products()->create([
            'name' => 'Aventus', 'template' => 'decant', 'attributes' => ['concentration' => 'edp', 'gender' => 'male'],
        ]);
        $ten = $aventus->variants()->create(['size_ml' => 10, 'price_mmk' => 40000]);
        $this->order([[$ten, 2]]);
        $tee = Product::create(['name' => 'Tee', 'template' => 'clothing']);
        $first = app(TenantContext::class)->get();
        app(TenantContext::class)->set(Shop::create(['name' => 'Other', 'slug' => 'other', 'status' => ShopStatus::Live]));
        $theirs = Product::create(['name' => 'Aventus', 'template' => 'decant', 'stock_amount' => 7]);
        app(TenantContext::class)->set($first);

        $money = fn (): array => DB::table('order_items')->orderBy('id')
            ->get(['id', 'size_ml', 'unit_price_mmk', 'unit_cost_mmk', 'line_total_mmk', 'line_cost_mmk'])
            ->map(fn (object $row) => (array) $row)->all();
        $before = $money();

        $migration = require database_path('migrations/2026_10_01_000001_add_weight_units.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('products', 'stock_unit'));
        $this->assertFalse(Schema::hasColumn('order_items', 'measure'));
        $migration->up();

        $this->assertSame($before, $money());
        $units = DB::table('products')->orderBy('id')->pluck('stock_unit', 'id')->all();
        $this->assertSame([$aventus->id => 'ml', $tee->id => null, $theirs->id => 'ml'], $units);
        $this->assertSame([10], DB::table('order_items')->pluck('measure')->map(fn ($v) => (int) $v)->all());

        // Once anything is weighed, down refuses rather than drop it.
        $this->lahpet();
        try {
            $migration->down();
            $this->fail('Expected the rollback to refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('counted by weight', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('products', 'stock_unit'));
    }

    private function assertRefused(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected the unit switch to be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('counted in ml', $e->getMessage());
        }
    }
}

/** Local produce by weight, for these tests only (the real template is row 15). */
class TestWeighedTemplate extends Template
{
    public function key(): string
    {
        return 'test-weighed';
    }

    public function group(): int
    {
        return 1;
    }

    public function attributes(): array
    {
        return [Attribute::text('origin', 'Region of origin', searchable: true)];
    }

    public function variantOptions(): array
    {
        return ['Weight'];
    }

    public function measure(): ?string
    {
        return StockUnit::KYATTHAR;
    }

    public function stockMode(): string
    {
        return self::STOCK_POOLED;
    }

    public function brandRequired(): bool
    {
        return false;
    }

    public function productNouns(): array
    {
        return ['product', 'products'];
    }

    public function defaultModules(): array
    {
        return [];
    }
}

<?php

namespace Tests\Feature;

use App\Enums\ShopStatus;
use App\Models\Brand;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Step 36's backfill, on its own seeded rows: the parity test's fixture is created
 * after migrations run, so it can't see a bad backfill (step 35 risks). The rows are
 * seeded, stripped back to what a legacy row carries, and run through the
 * migration's own backfill. (The full down → up round trip, with data, is run on
 * Postgres: SQLite rebuilds a table to change a column, which inside the test's
 * transaction fires the foreign-key actions a real migration switches off.)
 */
class ProductMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_backfill_matches_each_line_to_its_own_shops_variant_and_moves_no_money(): void
    {
        $a = $this->seedShop(Shop::create(['name' => 'First', 'slug' => 'first', 'status' => ShopStatus::Live]));
        $b = $this->seedShop(Shop::create(['name' => 'Other', 'slug' => 'other', 'status' => ShopStatus::Live]));
        app(TenantContext::class)->set($a['shop']);

        $before = DB::table('order_items')->orderBy('id')
            ->get(['id', 'product_id', 'size_ml', 'unit_price_mmk', 'unit_cost_mmk', 'line_total_mmk', 'line_cost_mmk', 'quantity', 'fragrance_name_snapshot'])
            ->map(fn (object $row) => (array) $row)->all();

        // Back to what the migration finds on a legacy row: none of the new
        // columns filled in (the model hooks filled them when the fixture was made).
        DB::table('order_items')->update(['product_variant_id' => null, 'variant_label_snapshot' => null]);
        DB::table('product_variants')->update(['options' => null, 'measure' => null]);

        $migration = require database_path('migrations/2026_09_26_000000_rename_catalog_to_products_and_variants.php');
        $migration->backfillVariants();
        $migration->backfillOrderItems();

        // Money and the frozen snapshots are byte-identical; only the column name moved.
        $after = DB::table('order_items')->orderBy('id')
            ->get(['id', 'product_id', 'size_ml', 'unit_price_mmk', 'unit_cost_mmk', 'line_total_mmk', 'line_cost_mmk', 'quantity', 'fragrance_name_snapshot'])
            ->map(fn (object $row) => (array) $row)->all();
        $this->assertSame($before, $after);

        foreach ([$a, $b] as $seeded) {
            $items = DB::table('order_items')->where('order_items.shop_id', $seeded['shop']->id)->orderBy('id')->get();

            $this->assertSame(
                [$seeded['ten'], $seeded['five'], null],
                $items->pluck('product_variant_id')->map(fn ($id) => $id === null ? null : (int) $id)->all(),
            );
            $this->assertSame(['10ml', '5ml', '7ml'], $items->pluck('variant_label_snapshot')->all());

            $variants = DB::table('product_variants')->where('product_variants.shop_id', $seeded['shop']->id)
                ->orderBy('size_ml')->get();
            $this->assertSame([5, 10, 30], $variants->pluck('size_ml')->map(fn ($v) => (int) $v)->all());
            $this->assertSame([0, 0, 0], $variants->pluck('position')->map(fn ($v) => (int) $v)->all());
            $this->assertSame([5, 10, 30], $variants->pluck('measure')->map(fn ($v) => (int) $v)->all());
            $this->assertSame(['Size' => '10ml'], json_decode($variants[1]->options, true));
            $this->assertTrue((bool) $variants[1]->is_active);
        }
    }

    /**
     * One product with 30ml/5ml/10ml variants (created out of size order), and three lines: 10ml, 5ml, and a 7ml that
     * has no variant.
     *
     * @return array{shop: Shop, ten: int, five: int}
     */
    private function seedShop(Shop $shop): array
    {
        app(TenantContext::class)->set($shop);

        $product = Brand::create(['name' => 'Chanel', 'type' => 'designer'])->products()->create([
            'name' => 'Allure Homme Sport', 'concentration' => 'cologne', 'gender' => 'male',
            'bottle_cost_mmk' => 100000, 'bottle_volume_ml' => 30,
        ]);
        $product->variants()->create(['size_ml' => 30, 'price_mmk' => 150000]);
        $five = $product->variants()->create(['size_ml' => 5, 'price_mmk' => 30000]);
        $ten = $product->variants()->create(['size_ml' => 10, 'price_mmk' => 55000]);

        $order = Order::create([
            'customer_name' => 'Su Su', 'phone' => '09-771234561', 'address' => 'Yangon',
            'order_from' => 'tiktok', 'status' => 'pending',
        ]);
        foreach ([[10, 55000, 2], [5, 30000, 1], [7, 40000, 1]] as [$size, $price, $quantity]) {
            $order->items()->create([
                'product_id' => $product->id, 'fragrance_name_snapshot' => 'Chanel Allure Homme Sport',
                'size_ml' => $size, 'unit_price_mmk' => $price, 'quantity' => $quantity,
            ]);
        }

        return ['shop' => $shop, 'ten' => $ten->id, 'five' => $five->id];
    }
}

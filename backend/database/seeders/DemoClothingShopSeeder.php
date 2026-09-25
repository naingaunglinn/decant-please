<?php

namespace Database\Seeders;

use App\Enums\ShopStatus;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Shop;
use App\Support\TenantContext;
use App\Templates\Templates;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * A demo clothing shop for local browser checks (step 38b) — the second category
 * end to end: brandless and branded items, Size + Color variants, one sold-out
 * combination, a size guide. Not in DatabaseSeeder's chain; run it on purpose:
 *
 *   php artisan db:seed --class=DemoClothingShopSeeder
 *
 * Served at clothing.decant.localhost:3001 (like verify-tenant-hosts' verify-b).
 * Idempotent: rerunning updates the same rows. Everything is written under the
 * demo shop's own context — no withoutTenancy().
 */
class DemoClothingShopSeeder extends Seeder
{
    public const SLUG = 'demo-clothing';

    public const HOST = 'clothing.decant.localhost:3001';

    public function run(): void
    {
        $shop = Shop::firstOrCreate(
            ['slug' => self::SLUG],
            ['name' => 'Thida Closet', 'status' => ShopStatus::Live],
        );
        $shop->syncDomains([['host' => self::HOST, 'is_primary' => true, 'verified' => true]]);
        Templates::assignToShop($shop, 'clothing');

        $context = app(TenantContext::class);
        $previous = $context->get();
        $context->set($shop);

        try {
            $this->call(DeliveryZoneSeeder::class); // Yangon at the demo fee, so checkout completes

            $brand = Brand::firstOrCreate(['name' => 'Shwe Thread'], ['type' => 'designer', 'is_active' => true]);

            foreach (self::products($brand) as $row) {
                $product = Product::updateOrCreate(['name' => $row['name']], $row['product']);

                foreach ($row['variants'] as $position => [$size, $color, $price, $inStock]) {
                    $product->variants()->updateOrCreate(
                        ['options->Size' => $size, 'options->Color' => $color],
                        ['options' => ['Size' => $size, 'Color' => $color], 'price_mmk' => $price, 'in_stock' => $inStock, 'position' => $position],
                    );
                }
            }
        } finally {
            $context->set($previous);
        }

        // /meta and /brands cache per shop for 10 minutes
        Cache::forget('api.meta.'.self::SLUG);
        Cache::forget('api.brands.'.self::SLUG);
    }

    /** @return list<array{name: string, product: array<string, mixed>, variants: list<array{string, string, int, bool}>}> */
    private static function products(Brand $brand): array
    {
        $guide = "S — chest 34 in, length 25 in\nM — chest 36 in, length 26 in\nL — chest 38 in, length 27 in";

        return [
            [
                'name' => 'Linen Shirt',
                'product' => [
                    'brand_id' => null,
                    'attributes' => ['material' => 'linen', 'gender' => 'women', 'size_guide' => $guide],
                    'description' => 'Loose, breathable linen for Yangon afternoons.',
                    'is_active' => true,
                    'is_featured' => true,
                ],
                'variants' => [
                    ['S', 'White', 18000, true],
                    ['M', 'White', 18000, true],
                    ['M', 'Blue', 19000, true],
                    ['L', 'Blue', 19000, false], // sold out: L comes only in Blue
                ],
            ],
            [
                'name' => 'Cotton Longyi',
                'product' => [
                    'brand_id' => $brand->id,
                    'attributes' => ['material' => 'cotton', 'gender' => 'men'],
                    'is_active' => true,
                    'is_featured' => false,
                ],
                'variants' => [
                    ['Free size', 'Green', 25000, true],
                    ['Free size', 'Maroon', 25000, true],
                ],
            ],
            [
                'name' => 'Silk Blouse',
                'product' => [
                    'brand_id' => null,
                    'attributes' => ['material' => 'silk', 'gender' => 'women', 'size_guide' => $guide],
                    'is_active' => true,
                    'is_featured' => true,
                ],
                'variants' => [
                    ['S', 'Cream', 42000, true],
                    ['M', 'Cream', 42000, true],
                    ['M', 'Black', 45000, true],
                ],
            ],
        ];
    }
}

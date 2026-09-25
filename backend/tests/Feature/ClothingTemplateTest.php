<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Studio\Resources\Shops\Pages\ManageShops;
use App\Models\Brand;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ShopSetting;
use App\Models\User;
use App\Support\CatalogImport;
use App\Support\TenantContext;
use App\Templates\Templates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step 38: the clothing template — Size + Color variants, a material attribute, a
 * photo per variant — through the admin, the catalog API, checkout and every
 * surface that used to print "{size_ml}ml".
 */
class ClothingTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ShopSetting::current()->update(['template' => 'clothing']);
    }

    /** @param  array<array{0: string, 1: string, 2: int, 3?: bool}>  $variants  size, color, price, in stock */
    private function shirt(string $name = 'Linen Shirt', string $material = 'linen', array $variants = [['M', 'Blue', 25000], ['L', 'Red', 27000]]): Product
    {
        $brand = Brand::firstOrCreate(['name' => 'Yangon Threads'], ['type' => 'designer']);
        $product = $brand->products()->create(['name' => $name, 'attributes' => ['material' => $material, 'gender' => 'unisex']]);

        foreach ($variants as $position => $variant) {
            $product->variants()->create([
                'options' => ['Size' => $variant[0], 'Color' => $variant[1]],
                'price_mmk' => $variant[2],
                'in_stock' => $variant[3] ?? true,
                'position' => $position,
            ]);
        }

        return $product;
    }

    private function variant(Product $product, string $label): ProductVariant
    {
        return $product->variants()->get()->first(fn (ProductVariant $variant) => $variant->label() === $label);
    }

    // ---- admin ----

    public function test_admin_creates_a_clothing_product_with_size_color_variants_and_a_photo_under_the_shop_prefix(): void
    {
        Storage::fake(config('filesystems.media_disk'));
        $this->actingAs($this->studioUser());
        $brand = Brand::create(['name' => 'Yangon Threads', 'type' => 'designer']);

        Livewire::test(CreateProduct::class)
            ->assertFormFieldHidden('stock_ml')
            ->assertFormFieldHidden('bottle_cost_mmk')
            ->fillForm([
                'brand_id' => $brand->id,
                'name' => 'Linen Shirt',
                'attributes' => ['material' => 'linen', 'gender' => 'women'],
                'variants' => [
                    ['options' => ['Size' => 'M', 'Color' => 'Blue'], 'price_mmk' => 25000, 'in_stock' => true, 'is_active' => true,
                        'image_path' => [UploadedFile::fake()->image('blue.jpg')]],
                    ['options' => ['Size' => 'L', 'Color' => 'Blue'], 'price_mmk' => 27000, 'in_stock' => true, 'is_active' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('name', 'Linen Shirt')->firstOrFail();
        $this->assertSame('clothing', $product->template);
        $this->assertSame('Linen', $product->attrDisplay('material'));

        $variants = $product->variants()->get();
        $this->assertSame(['M / Blue', 'L / Blue'], $variants->map->label()->all());
        $this->assertSame([null, null], $variants->pluck('size_ml')->all());
        $this->assertSame([25000, 27000], $variants->pluck('price_mmk')->all());

        $shopId = app(TenantContext::class)->id();
        $this->assertStringStartsWith("shops/{$shopId}/variants/", $variants[0]->image_path);
        Storage::disk(config('filesystems.media_disk'))->assertExists($variants[0]->image_path);
        $this->assertNull($variants[1]->image_path);
    }

    public function test_the_same_size_and_colour_twice_is_refused(): void
    {
        $this->actingAs($this->studioUser());
        $brand = Brand::create(['name' => 'Yangon Threads', 'type' => 'designer']);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'brand_id' => $brand->id,
                'name' => 'Linen Shirt',
                'attributes' => ['material' => 'linen'],
                'variants' => [
                    ['options' => ['Size' => 'M', 'Color' => 'Blue'], 'price_mmk' => 25000],
                    ['options' => ['Size' => 'm', 'Color' => ' blue '], 'price_mmk' => 26000],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['variants']);

        $this->assertTrue(Product::where('name', 'Linen Shirt')->doesntExist());
    }

    public function test_the_edit_form_keeps_a_decant_product_on_ml_sizes_in_a_clothing_shop(): void
    {
        // A product keeps its own template: the decant form, stock section included.
        $this->actingAs($this->studioUser());
        $brand = Brand::create(['name' => 'Chanel', 'type' => 'designer']);
        $allure = $brand->products()->create([
            'name' => 'Allure', 'template' => 'decant', 'attributes' => ['concentration' => 'edp', 'gender' => 'male'],
        ]);
        $allure->variants()->create(['size_ml' => 10, 'price_mmk' => 55000]);

        Livewire::test(EditProduct::class, ['record' => $allure->getRouteKey()])
            ->assertFormFieldVisible('stock_ml')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([10], $allure->variants()->pluck('size_ml')->all());
    }

    public function test_variant_options_are_trimmed_and_kept_in_the_templates_order(): void
    {
        $product = Product::create(['name' => 'Tee', 'brand_id' => Brand::create(['name' => 'B', 'type' => 'designer'])->id]);
        $variant = $product->variants()->create(['options' => ['Color' => ' Blue ', 'Size' => 'M'], 'price_mmk' => 10000]);

        $this->assertSame(['Size' => 'M', 'Color' => 'Blue'], $variant->options);
        $this->assertSame('M / Blue', $variant->label());
        $this->assertNull($variant->size_ml);
        $this->assertNull($variant->measure);
    }

    public function test_csv_import_is_refused_for_a_clothing_shop(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CatalogImport::run(CatalogImport::template());
    }

    public function test_the_studio_registers_a_clothing_shop(): void
    {
        $this->actingAs(User::factory()->studio()->create());

        Livewire::test(ManageShops::class)
            ->callAction('create', data: [
                'name' => 'Bahan Boutique',
                'slug' => 'bahan-boutique',
                'template' => 'clothing',
                'status' => 'live',
                'create_owner' => false,
            ])
            ->assertHasNoActionErrors();

        $boutique = Shop::where('slug', 'bahan-boutique')->firstOrFail();
        $context = app(TenantContext::class);
        $default = $context->get();

        $context->set($boutique);
        $this->assertSame('clothing', Templates::shopDefaultKey());

        // written as the new shop's row; the operator's own context is restored
        $context->set($default);
        $this->assertSame(1, ShopSetting::query()->count());

        Livewire::test(ManageShops::class)
            ->callAction('create', data: ['name' => 'X', 'slug' => 'x-shop', 'template' => 'nope', 'status' => 'live', 'create_owner' => false])
            ->assertHasActionErrors(['template']);
    }

    // ---- the catalog API ----

    public function test_the_api_carries_variant_options_and_photos(): void
    {
        $shirt = $this->shirt();
        $this->variant($shirt, 'M / Blue')->update(['image_path' => 'shops/1/variants/blue.jpg']);

        $prices = $this->getJson('/api/v1/decant-please/products/yangon-threads-linen-shirt')
            ->assertOk()
            ->assertJsonPath('data.template', 'clothing')
            ->assertJsonPath('data.attributes.0.display', 'Linen')
            ->json('data.prices');

        $this->assertSame(['M / Blue', 'L / Red'], array_column($prices, 'label'));
        $this->assertSame([['Size' => 'M', 'Color' => 'Blue'], ['Size' => 'L', 'Color' => 'Red']], array_column($prices, 'options'));
        $this->assertSame([null, null], array_column($prices, 'size_ml'));
        $this->assertStringEndsWith('shops/1/variants/blue.jpg', $prices[0]['image_url']);
        $this->assertNull($prices[1]['image_url']);
    }

    public function test_products_filter_by_material_and_by_one_variant_matching_every_picked_option(): void
    {
        $this->shirt('Linen Shirt', 'linen', [['M', 'Blue', 25000], ['L', 'Red', 27000]]);
        $this->shirt('Cotton Tee', 'cotton', [['M', 'Red', 12000], ['L', 'Blue', 13000], ['S', 'Blue', 11000, false]]);

        $names = fn (string $query): array => array_column(
            $this->getJson("/api/v1/decant-please/products?{$query}")->assertOk()->json('data'), 'name');

        $this->assertSame(['Linen Shirt'], $names('material=linen'));
        $this->assertSame(['Cotton Tee', 'Linen Shirt'], $names('option[Size]=M'));
        // the tee has M and has Blue, but no M / Blue variant
        $this->assertSame(['Linen Shirt'], $names('option[Size]=M&option[Color]=Blue'));
        // S / Blue exists but is out of stock
        $this->assertSame([], $names('option[Size]=S'));
        $this->assertSame(['Cotton Tee'], $names('material=cotton&option[Color]=Blue'));

        $this->getJson('/api/v1/decant-please/products?option[Fit]=slim')->assertUnprocessable();
    }

    public function test_meta_lists_the_clothing_filters_and_in_stock_option_values(): void
    {
        $this->shirt('Linen Shirt', 'linen', [['M', 'Blue', 25000], ['L', 'Red', 27000]]);
        $this->shirt('Cotton Tee', 'cotton', [['S', 'Green', 11000, false], ['M', 'Red', 12000]]);

        $meta = $this->getJson('/api/v1/decant-please/meta')->assertOk()->json();

        $this->assertSame(['material', 'gender'], array_column($meta['filters'], 'key'));
        $this->assertSame([], $meta['sizes']); // no ml sizes, never [null]
        $this->assertSame([
            ['name' => 'Size', 'values' => ['M', 'L']],
            ['name' => 'Color', 'values' => ['Blue', 'Red']],
        ], $meta['variant_options']);
        $this->assertSame(['min' => 12000, 'max' => 27000], $meta['price']);
    }

    public function test_a_decant_shop_gets_no_variant_option_filters(): void
    {
        ShopSetting::current()->update(['template' => 'decant']);

        $this->getJson('/api/v1/decant-please/meta')->assertOk()->assertJsonPath('variant_options', []);
        // and ?option[] is not a decant parameter: ignored, as before
        $this->getJson('/api/v1/decant-please/products?option[Size]=M')->assertOk();
    }

    // ---- checkout and after ----

    private function checkout(array $items): Order
    {
        $response = $this->postJson('/api/v1/decant-please/orders', [
            'customer_name' => 'Su Su',
            'phone' => '09-771234561',
            'delivery_township_id' => $this->serviceableTownship(fee: 3000)->id,
            'address_line' => 'No. 12, Baho Road',
            'total_mmk' => 1, // smuggled — ignored
            'items' => $items,
        ])->assertCreated();

        return Order::where('tracking_code', $response->json('tracking_code'))->firstOrFail();
    }

    public function test_checkout_by_variant_derives_the_price_and_freezes_the_variant_label(): void
    {
        $shirt = $this->shirt();
        $blue = $this->variant($shirt, 'M / Blue');
        $red = $this->variant($shirt, 'L / Red');

        $order = $this->checkout([
            ['variant_id' => $blue->id, 'quantity' => 2, 'unit_price_mmk' => 1],
            ['variant_id' => $red->id, 'quantity' => 1],
        ]);

        // 2 × 25,000 + 27,000 + 3,000 delivery
        $this->assertSame(80000, $order->total_mmk);
        $items = $order->items()->orderBy('id')->get();
        $this->assertSame(['M / Blue', 'L / Red'], $items->pluck('variant_label_snapshot')->all());
        $this->assertSame([$blue->id, $red->id], $items->pluck('product_variant_id')->all());
        $this->assertSame([null, null], $items->pluck('size_ml')->all());
        $this->assertSame([null, null], $items->pluck('unit_cost_mmk')->all());

        // renaming the colour later never moves the placed order's label
        $blue->update(['options' => ['Size' => 'M', 'Color' => 'Navy']]);
        $this->assertSame('M / Blue', $items[0]->fresh()->variantLabel());

        $this->getJson('/api/v1/decant-please/orders/track?'.http_build_query(['tracking_code' => $order->tracking_code, 'phone' => '09-771234561']))
            ->assertOk()
            ->assertJsonPath('items.0.variant_label', 'M / Blue')
            ->assertJsonPath('items.0.size_ml', null);
    }

    public function test_the_invoice_prints_the_variant_label(): void
    {
        $order = $this->checkout([['variant_id' => $this->variant($this->shirt(), 'L / Red')->id, 'quantity' => 1]]);
        $order->load('items');

        $html = view('pdf.invoice', ['order' => $order])->render();

        $this->assertStringContainsString('L / Red', $html);
        $this->assertStringNotContainsString('ml</td>', $html);
    }

    public function test_an_archived_clothing_variant_is_refused_at_checkout(): void
    {
        $shirt = $this->shirt();
        $blue = $this->variant($shirt, 'M / Blue');
        $blue->update(['is_active' => false]);

        $this->postJson('/api/v1/decant-please/orders', [
            'customer_name' => 'Su Su',
            'phone' => '09-771234561',
            'delivery_township_id' => $this->serviceableTownship()->id,
            'address_line' => 'No. 12, Baho Road',
            'items' => [['variant_id' => $blue->id, 'quantity' => 1]],
        ])->assertUnprocessable();

        $this->assertSame(0, Order::count());
    }

    public function test_an_admin_edit_of_a_clothing_order_saves_and_keeps_its_line(): void
    {
        $this->actingAs($this->studioUser());
        // A DM order (no township), as AdminOrdersTest does: the checkout-order edit
        // page has a pre-existing township TypeError, noted in PR #120.
        $blue = $this->variant($this->shirt(), 'M / Blue');
        $order = Order::create([
            'customer_name' => 'Manual Customer', 'phone' => '09-700000000', 'address' => 'Yangon',
            'order_from' => 'tiktok', 'status' => OrderStatus::Pending, 'prep_date' => today()->addDay(),
        ]);
        $order->items()->create([
            'product_id' => $blue->product_id, 'product_variant_id' => $blue->id,
            'fragrance_name_snapshot' => 'Yangon Threads Linen Shirt', 'unit_price_mmk' => 25000, 'quantity' => 1,
        ]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->fillForm(['customer_name' => 'Aung Kyaw Oo'])
            ->call('save')
            ->assertHasNoFormErrors();

        $item = $order->items()->first();
        $this->assertSame('Aung Kyaw Oo', $order->fresh()->customer_name);
        $this->assertSame('M / Blue', $item->variant_label_snapshot);
        $this->assertNull($item->size_ml);
        $this->assertSame(25000, $item->unit_price_mmk);
    }

    public function test_switching_a_clothing_lines_variant_in_the_admin_refreezes_its_label_and_autofills_its_price(): void
    {
        $this->actingAs($this->studioUser());
        $shirt = $this->shirt();
        $blue = $this->variant($shirt, 'M / Blue');
        $red = $this->variant($shirt, 'L / Red');
        $order = Order::create([
            'customer_name' => 'Manual Customer', 'phone' => '09-700000000', 'address' => 'Yangon',
            'order_from' => 'tiktok', 'status' => OrderStatus::Pending, 'prep_date' => today()->addDay(),
        ]);
        $item = $order->items()->create([
            'product_id' => $shirt->id, 'product_variant_id' => $blue->id,
            'fragrance_name_snapshot' => 'Yangon Threads Linen Shirt', 'unit_price_mmk' => 25000, 'quantity' => 1,
        ]);
        $line = "data.items.record-{$item->id}";

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertFormFieldHidden("items.record-{$item->id}.size_ml")
            ->set("{$line}.product_variant_id", $red->id)
            ->assertSet("{$line}.unit_price_mmk", 27000) // autofilled from the picked variant
            ->call('save')
            ->assertHasNoFormErrors();

        $item->refresh();
        $this->assertSame($red->id, $item->product_variant_id);
        $this->assertSame('L / Red', $item->variant_label_snapshot);
        $this->assertNull($item->size_ml);
        $this->assertSame(27000, $item->unit_price_mmk);
        $this->assertSame(27000, $order->fresh()->total_mmk);

        // another product's variant is not an option for this line
        $other = $this->variant($this->shirt('Cotton Tee', 'cotton', [['S', 'Green', 11000]]), 'S / Green');

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->set("{$line}.product_variant_id", $other->id)
            ->call('save')
            ->assertHasFormErrors(["items.record-{$item->id}.product_variant_id"]);

        $this->assertSame($red->id, $item->fresh()->product_variant_id);
    }

    public function test_the_production_schedule_keeps_each_variant_on_its_own_line(): void
    {
        $shirt = $this->shirt();
        $order = $this->checkout([
            ['variant_id' => $this->variant($shirt, 'M / Blue')->id, 'quantity' => 2],
            ['variant_id' => $this->variant($shirt, 'L / Red')->id, 'quantity' => 1],
        ]);
        $order->update(['status' => OrderStatus::Pending, 'prep_date' => '2026-10-01']);

        $day = CarbonImmutable::parse('2026-10-01');
        $groups = Order::productionScheduleFor($day, $day)[0]['groups'];

        // in the seller's variant order (M before L), not alphabetical
        $this->assertSame([['M / Blue', 2], ['L / Red', 1]], $groups->map(fn (array $group) => [$group['variant_label'], $group['quantity']])->all());
    }
}

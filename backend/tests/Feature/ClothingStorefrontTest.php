<?php

namespace Tests\Feature;

use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Brand;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopSetting;
use App\Support\TenantContext;
use Database\Seeders\DemoClothingShopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step 38b: what the storefront needs from the API for a clothing shop — an
 * optional brand (`brand: null`), the size guide, and the demo shop the browser
 * checks run against.
 */
class ClothingStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ShopSetting::current()->update(['template' => 'clothing']);
    }

    private function shirt(?Brand $brand = null, string $name = 'Linen Shirt', array $attributes = ['material' => 'linen']): Product
    {
        $product = Product::create(['brand_id' => $brand?->id, 'name' => $name, 'attributes' => $attributes]);
        $product->variants()->create(['options' => ['Size' => 'M', 'Color' => 'Blue'], 'price_mmk' => 25000, 'in_stock' => true]);

        return $product;
    }

    // ---- optional brand ----

    public function test_a_brandless_product_is_listed_and_shown_with_brand_null(): void
    {
        $this->shirt();

        $listed = $this->getJson('/api/v1/decant-please/products')->assertOk()->json('data');
        $this->assertSame(['Linen Shirt'], array_column($listed, 'name'));
        $this->assertArrayHasKey('brand', $listed[0]);
        $this->assertNull($listed[0]['brand']); // literally null — not {} or []

        $shown = $this->getJson('/api/v1/decant-please/products/linen-shirt')->assertOk()->json('data');
        $this->assertArrayHasKey('brand', $shown);
        $this->assertNull($shown['brand']);

        $this->assertSame(['min' => 25000, 'max' => 25000], $this->getJson('/api/v1/decant-please/meta')->json('price'));
    }

    public function test_brand_types_are_a_decant_concept_only(): void
    {
        $this->assertSame([], $this->getJson('/api/v1/decant-please/meta')->json('brand_types'));

        ShopSetting::current()->update(['template' => 'decant']);

        $this->assertSame(['designer', 'niche'], array_column($this->getJson('/api/v1/decant-please/meta')->json('brand_types'), 'value'));
    }

    public function test_a_hidden_brand_still_hides_its_products_but_not_the_brandless_ones(): void
    {
        $hidden = Brand::create(['name' => 'Hidden House', 'type' => 'designer', 'is_active' => false]);
        $hiddenTee = $this->shirt($hidden, 'Hidden Tee');
        $this->shirt(null, 'Linen Shirt');

        $this->assertSame(['Linen Shirt'], array_column($this->getJson('/api/v1/decant-please/products')->json('data'), 'name'));
        $this->getJson("/api/v1/decant-please/products/{$hiddenTee->slug}")->assertNotFound();
    }

    public function test_checkout_sells_a_brandless_variant_at_the_server_price_and_snapshots_the_bare_name(): void
    {
        $variant = $this->shirt()->variants()->firstOrFail();

        $response = $this->postJson('/api/v1/decant-please/orders', [
            'customer_name' => 'Su Su',
            'phone' => '09-771234561',
            'delivery_township_id' => $this->serviceableTownship(fee: 3000)->id,
            'address_line' => 'No. 12, Baho Road',
            'total_mmk' => 1, // smuggled — ignored
            'items' => [['variant_id' => $variant->id, 'quantity' => 2, 'unit_price_mmk' => 1]],
        ])->assertCreated();

        $order = Order::where('tracking_code', $response->json('tracking_code'))->firstOrFail();
        // 2 × 25,000 + 3,000 delivery
        $this->assertSame(53000, $order->total_mmk);
        $this->assertSame('Linen Shirt', $order->items()->firstOrFail()->fragrance_name_snapshot); // no leading space

        $this->getJson('/api/v1/decant-please/orders/track?'.http_build_query(['tracking_code' => $order->tracking_code, 'phone' => '09-771234561']))
            ->assertOk()
            ->assertJsonPath('items.0.fragrance_name', 'Linen Shirt')
            ->assertJsonPath('items.0.variant_label', 'M / Blue');
    }

    public function test_an_inactive_or_sold_out_brandless_product_is_not_sold_and_the_message_names_the_variant(): void
    {
        $shirt = $this->shirt();
        $variant = $shirt->variants()->firstOrFail();
        $order = fn (): array => $this->postJson('/api/v1/decant-please/orders', [
            'customer_name' => 'Su Su',
            'phone' => '09-771234561',
            'delivery_township_id' => $this->serviceableTownship()->id,
            'address_line' => 'No. 12, Baho Road',
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertUnprocessable()->json('errors');

        $variant->update(['in_stock' => false]);
        $this->assertSame('M / Blue of Linen Shirt just sold out — pick another size.', $order()['items.0'][0]);

        $variant->update(['in_stock' => true]);
        $shirt->update(['is_active' => false]);
        $this->assertSame('That fragrance is no longer available.', $order()['items.0'][0]);
        $this->assertSame([], $this->getJson('/api/v1/decant-please/products')->json('data'));
        $this->getJson("/api/v1/decant-please/products/{$shirt->slug}")->assertNotFound();
    }

    public function test_another_shops_brandless_product_never_crosses_over(): void
    {
        $context = app(TenantContext::class);
        $home = $context->get();
        $other = Shop::factory()->create(['slug' => 'thida-closet', 'name' => 'Thida Closet']);
        $context->set($other);
        ShopSetting::current()->update(['template' => 'clothing']);
        $foreign = $this->shirt(null, 'Foreign Shirt')->variants()->firstOrFail();
        $context->set($home);

        $this->assertSame([], $this->getJson('/api/v1/decant-please/products')->json('data'));
        $this->getJson('/api/v1/decant-please/products/foreign-shirt')->assertNotFound();
        $this->assertSame(['min' => null, 'max' => null], $this->getJson('/api/v1/decant-please/meta')->json('price'));

        $this->postJson('/api/v1/decant-please/orders', [
            'customer_name' => 'Su Su',
            'phone' => '09-771234561',
            'delivery_township_id' => $this->serviceableTownship()->id,
            'address_line' => 'No. 12, Baho Road',
            'items' => [['variant_id' => $foreign->id, 'quantity' => 1]],
        ])->assertUnprocessable();

        $this->assertSame(['Foreign Shirt'], array_column($this->getJson('/api/v1/thida-closet/products')->json('data'), 'name'));
    }

    public function test_the_clothing_form_saves_without_a_brand_and_the_decant_form_still_requires_one(): void
    {
        $this->actingAs($this->studioUser());

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Linen Shirt',
                'attributes' => ['material' => 'linen'],
                'variants' => [['options' => ['Size' => 'M', 'Color' => 'Blue'], 'price_mmk' => 25000, 'in_stock' => true, 'is_active' => true]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Product::where('name', 'Linen Shirt')->firstOrFail()->brand_id);

        ShopSetting::current()->update(['template' => 'decant']);

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Aventus', 'attributes' => ['concentration' => 'edp', 'gender' => 'male']])
            ->call('create')
            ->assertHasFormErrors(['brand_id' => 'required']);
    }

    public function test_a_clothing_shops_brand_form_asks_no_designer_or_niche_type(): void
    {
        $this->actingAs($this->studioUser());

        Livewire::test(CreateBrand::class)
            ->assertFormFieldHidden('type')
            ->fillForm(['name' => 'Shwe Thread'])
            ->call('create')
            ->assertHasNoFormErrors();

        // the column's default type is stored, but no clothing surface shows it
        $this->assertTrue(Brand::where('name', 'Shwe Thread')->exists());
        $this->assertSame([], $this->getJson('/api/v1/decant-please/meta')->json('brand_types'));

        ShopSetting::current()->update(['template' => 'decant']);

        Livewire::test(CreateBrand::class)->assertFormFieldVisible('type');
    }

    // ---- size guide ----

    public function test_the_size_guide_is_a_section_with_its_line_breaks_kept(): void
    {
        $this->shirt(null, 'Linen Shirt', ['material' => 'linen', 'size_guide' => "S — chest 34 in\nM — chest 36 in"]);

        $attributes = collect($this->getJson('/api/v1/decant-please/products/linen-shirt')->json('data.attributes'))->keyBy('key');

        $this->assertSame('section', $attributes['size_guide']['show']);
        $this->assertSame("S — chest 34 in\nM — chest 36 in", $attributes['size_guide']['display']);
        $this->assertSame('pill', $attributes['material']['show']);
        // not a filter, and not searched
        $this->assertNotContains('size_guide', array_column($this->getJson('/api/v1/decant-please/meta')->json('filters'), 'key'));
        $this->assertSame([], $this->getJson('/api/v1/decant-please/products?q=chest')->json('data'));
    }

    // ---- demo seeder ----

    public function test_the_demo_clothing_seeder_builds_a_whole_shop_idempotently_and_leaves_the_default_shop_alone(): void
    {
        $home = app(TenantContext::class)->get();

        $this->seed(DemoClothingShopSeeder::class);
        $this->seed(DemoClothingShopSeeder::class);

        $this->assertSame($home, app(TenantContext::class)->get());
        $this->assertSame(0, Product::count()); // nothing landed in the default shop

        $shop = Shop::where('slug', DemoClothingShopSeeder::SLUG)->firstOrFail();
        $this->assertSame(DemoClothingShopSeeder::HOST, $shop->domains()->where('is_primary', true)->value('host'));

        $products = collect($this->getJson('/api/v1/demo-clothing/products?per_page=50')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(['Cotton Longyi', 'Linen Shirt', 'Silk Blouse'], $products->keys()->sort()->values()->all());
        $this->assertSame(['clothing'], $products->pluck('template')->unique()->values()->all());
        $this->assertNull($products['Linen Shirt']['brand']);
        $this->assertSame('Shwe Thread', $products['Cotton Longyi']['brand']['name']);
        $this->assertSame(['S / White', 'M / White', 'M / Blue', 'L / Blue'], array_column($products['Linen Shirt']['prices'], 'label'));

        $meta = $this->getJson('/api/v1/demo-clothing/meta')->assertOk()->json();
        $this->assertSame('Size', $meta['variant_options'][0]['name']);
    }
}

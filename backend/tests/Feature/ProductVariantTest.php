<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 36: the catalog is products + variants. A variant is archived with
 * is_active, never deleted once sold: archived means gone from the storefront and
 * refused at checkout, while placed orders keep it.
 */
class ProductVariantTest extends TestCase
{
    use RefreshDatabase;

    private Product $allure;

    private ProductVariant $fiveMl;

    private ProductVariant $tenMl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allure = Brand::create(['name' => 'Chanel', 'type' => 'designer'])->products()->create([
            'name' => 'Allure Homme Sport', 'concentration' => 'cologne', 'gender' => 'male',
        ]);
        $this->fiveMl = $this->allure->variants()->create(['size_ml' => 5, 'price_mmk' => 30000]);
        $this->tenMl = $this->allure->variants()->create(['size_ml' => 10, 'price_mmk' => 55000]);
    }

    public function test_a_decant_variant_derives_its_options_and_measure_from_the_size(): void
    {
        $this->assertSame(['Size' => '5ml'], $this->fiveMl->options);
        $this->assertSame(5, $this->fiveMl->measure);
        $this->assertTrue($this->fiveMl->is_active);
        $this->assertSame('5ml', $this->fiveMl->label());

        $this->fiveMl->update(['size_ml' => 3]);
        $this->assertSame(['Size' => '3ml'], $this->fiveMl->fresh()->options);
        $this->assertSame(3, $this->fiveMl->fresh()->measure);
    }

    public function test_checkout_stamps_the_variant_and_its_label_on_the_line(): void
    {
        $response = $this->postJson('/api/v1/decant-please/orders', $this->payload(10))->assertCreated();

        $item = Order::where('tracking_code', $response->json('tracking_code'))->firstOrFail()->items()->sole();
        $this->assertSame($this->tenMl->id, $item->product_variant_id);
        $this->assertSame('10ml', $item->variant_label_snapshot);
        $this->assertSame(10, $item->size_ml);
        $this->assertSame(55000, $item->unit_price_mmk);
    }

    public function test_a_line_created_by_product_and_size_is_matched_to_its_variant(): void
    {
        $order = $this->order();
        $matched = $order->items()->create([
            'product_id' => $this->allure->id, 'fragrance_name_snapshot' => 'Chanel Allure Homme Sport',
            'size_ml' => 5, 'unit_price_mmk' => 30000, 'quantity' => 1,
        ]);
        $unmatched = $order->items()->create([
            'product_id' => $this->allure->id, 'fragrance_name_snapshot' => 'Chanel Allure Homme Sport',
            'size_ml' => 7, 'unit_price_mmk' => 40000, 'quantity' => 1,
        ]);

        $this->assertSame($this->fiveMl->id, $matched->product_variant_id);
        $this->assertSame('5ml', $matched->variant_label_snapshot);
        $this->assertNull($unmatched->product_variant_id);
        $this->assertSame('7ml', $unmatched->variant_label_snapshot);
    }

    public function test_an_archived_variant_is_hidden_from_the_catalog(): void
    {
        $this->tenMl->update(['is_active' => false]);

        $this->getJson('/api/v1/decant-please/fragrances/'.$this->allure->slug)
            ->assertOk()
            ->assertJsonCount(1, 'data.prices')
            ->assertJsonPath('data.prices.0.size_ml', 5);

        // min price, the size filter and /meta all ignore it too
        $this->tenMl->update(['price_mmk' => 1000]);
        $this->getJson('/api/v1/decant-please/fragrances')->assertJsonPath('data.0.min_price_mmk', 30000);
        $this->getJson('/api/v1/decant-please/fragrances?size=10')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/decant-please/meta')
            ->assertJsonPath('sizes', [5])
            ->assertJsonPath('price.min', 30000);
    }

    public function test_an_archived_variant_is_refused_at_checkout(): void
    {
        $this->tenMl->update(['is_active' => false]);

        $this->postJson('/api/v1/decant-please/orders', $this->payload(10))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0');
        $this->assertSame(0, Order::count());

        $this->postJson('/api/v1/decant-please/orders/validate-promo', [
            'code' => 'ANY', 'items' => [['fragrance_id' => $this->allure->id, 'size_ml' => 10, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0');
    }

    public function test_an_archived_variant_still_shows_on_a_placed_order(): void
    {
        $response = $this->postJson('/api/v1/decant-please/orders', $this->payload(10))->assertCreated();
        $this->tenMl->update(['is_active' => false, 'price_mmk' => 99000]);

        $this->getJson('/api/v1/decant-please/orders/track?'.http_build_query([
            'tracking_code' => $response->json('tracking_code'), 'phone' => '09-771234561',
        ]))
            ->assertOk()
            ->assertJsonPath('items.0.size_ml', 10)
            ->assertJsonPath('items.0.unit_price_mmk', 55000);

        $item = OrderItem::query()->sole();
        $this->assertSame($this->tenMl->id, $item->product_variant_id);
        $this->assertFalse($item->variant->is_active);
    }

    public function test_a_variant_on_an_order_cannot_be_deleted(): void
    {
        $this->postJson('/api/v1/decant-please/orders', $this->payload(10))->assertCreated();

        $this->expectException(QueryException::class);
        $this->tenMl->delete();
    }

    public function test_deleting_a_brand_keeps_its_products(): void
    {
        $this->allure->brand->delete();

        $this->assertNull($this->allure->fresh()->brand_id);
        $this->assertSame(2, $this->allure->variants()->count());
    }

    public function test_variants_list_by_position_then_size(): void
    {
        // A size added later still lists in size order, as decant sizes always have.
        $this->allure->variants()->create(['size_ml' => 30, 'price_mmk' => 150000]);
        $this->allure->variants()->create(['size_ml' => 3, 'price_mmk' => 20000]);
        $this->assertSame([3, 5, 10, 30], $this->allure->variants()->pluck('size_ml')->all());
        $this->getJson('/api/v1/decant-please/fragrances/'.$this->allure->slug)
            ->assertJsonPath('data.prices.*.size_ml', [3, 5, 10, 30]);

        $this->fiveMl->update(['position' => 2]);
        $this->assertSame([3, 10, 30, 5], $this->allure->variants()->pluck('size_ml')->all());
    }

    private function order(): Order
    {
        return Order::create([
            'customer_name' => 'Su Su', 'phone' => '09-771234561', 'address' => 'Yangon',
            'order_from' => 'tiktok', 'status' => 'pending',
        ]);
    }

    private function payload(int $sizeMl): array
    {
        return [
            'customer_name' => 'Su Su',
            'phone' => '09-771234561',
            'delivery_township_id' => $this->serviceableTownship()->id,
            'address_line' => 'No. 12, Inya Road',
            'items' => [['fragrance_id' => $this->allure->id, 'size_ml' => $sizeMl, 'quantity' => 1]],
        ];
    }
}

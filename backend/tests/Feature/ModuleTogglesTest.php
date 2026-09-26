<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PromoType;
use App\Enums\ShopStatus;
use App\Filament\Pages\ManageFeatures;
use App\Filament\Pages\ProductionSchedule;
use App\Filament\Pages\ProductionScheduleDay;
use App\Filament\Pages\ProfitAndLoss;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Filament\Widgets\DiscountCost;
use App\Filament\Widgets\LowStock;
use App\Filament\Widgets\OrderStats;
use App\Filament\Widgets\UpcomingDecants;
use App\Models\Brand;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Shop;
use App\Models\ShopSetting;
use App\Support\Modules;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step 41: optional modules. A shop with one off sees nothing of it (P3), and
 * the order path never breaks because of it (P2).
 */
class ModuleTogglesTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = [Modules::STOCK, Modules::COST_MARGIN, Modules::PRODUCTION_SCHEDULE, Modules::PROMO_CODES, Modules::EXPENSES];

    private function aventus(): Product
    {
        $brand = Brand::firstOrCreate(['name' => 'Creed'], ['type' => 'niche']);
        $product = $brand->products()->create([
            'name' => 'Aventus', 'stock_amount' => 100,
            'attributes' => ['concentration' => 'edp', 'gender' => 'male'],
            'reference_cost_mmk' => 100000, 'reference_amount' => 30,
        ]);
        $product->variants()->create(['size_ml' => 5, 'price_mmk' => 30000]);

        return $product;
    }

    private function allOff(): void
    {
        ShopSetting::current()->update(['modules' => []]);
    }

    /** @return list<string> the admin sidebar's item labels, as rendered on the products list */
    private function menu(): array
    {
        $html = $this->get(ProductResource::getUrl('index'))->assertOk()->getContent();
        preg_match_all('/fi-sidebar-item-label[^>]*>\s*([^<]+?)\s*</', $html, $matches);

        return $matches[1];
    }

    /** @return array<string, mixed> */
    private function payload(Product $product, array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Su Su',
            'phone' => '09-771234561',
            'delivery_township_id' => $this->serviceableTownship()->id,
            'address_line' => 'No. 12, Bahan Township, Yangon',
            'items' => [['variant_id' => $this->variantId($product, 5), 'quantity' => 2]],
        ];
    }

    // ---- defaults and the resolver ----

    public function test_a_shop_that_never_saved_runs_on_its_templates_defaults(): void
    {
        // decant: every module, so today's shop sees exactly what it saw before
        $this->assertNull(ShopSetting::currentOrNull());
        $this->assertSame(self::ALL, Modules::enabled());
        $this->assertNull(ShopSetting::currentOrNull()); // a read never creates the row

        // clothing: no production schedule (roadmap: decant only)
        ShopSetting::current()->update(['template' => 'clothing']);
        $this->assertSame([Modules::STOCK, Modules::COST_MARGIN, Modules::PROMO_CODES, Modules::EXPENSES], Modules::enabled());
        $this->assertFalse(Modules::on(Modules::PRODUCTION_SCHEDULE));
    }

    public function test_a_saved_set_overrides_the_defaults_and_drops_unknown_keys(): void
    {
        ShopSetting::current()->update(['modules' => ['expenses', 'retired_module', 'stock']]);

        // all() order, known keys only
        $this->assertSame([Modules::STOCK, Modules::EXPENSES], Modules::enabled());
        $this->assertFalse(Modules::on(Modules::PROMO_CODES));
    }

    public function test_one_shops_modules_never_reach_another(): void
    {
        $context = app(TenantContext::class);
        $first = $context->get();
        $this->allOff();
        $this->assertSame([], Modules::enabled());

        $other = Shop::create(['slug' => 'other-shop', 'name' => 'Other Shop', 'status' => ShopStatus::Live]);
        $context->set($other);
        $this->assertSame(self::ALL, Modules::enabled()); // same request, own entry

        $context->set($first);
        $this->assertSame([], Modules::enabled());
    }

    // ---- the Features page ----

    public function test_the_features_page_shows_the_defaults_and_saves_the_full_set(): void
    {
        $this->actingAs($this->studioUser());

        Livewire::test(ManageFeatures::class)
            ->assertOk()
            ->assertFormSet(['modules' => self::ALL])
            ->fillForm(['modules' => [Modules::STOCK, Modules::PROMO_CODES]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([Modules::STOCK, Modules::PROMO_CODES], ShopSetting::current()->modules);
        $this->assertFalse(Modules::on(Modules::EXPENSES)); // shows at once, same request
    }

    // ---- hidden surfaces ----

    public function test_the_menu_lists_every_module_by_default(): void
    {
        $this->actingAs($this->studioUser());

        // the baseline the next test's menu is compared against
        $this->assertSame(
            ['Dashboard', 'Brands', 'Fragrances', 'Orders', 'Production Schedule', 'Payment', 'Promo Codes', 'Profit &amp; loss', 'Expenses', 'Design · ဒီဇိုင်း', 'Features', 'Delivery Zones'],
            $this->menu(),
        );
    }

    public function test_a_module_off_hides_its_pages_resources_and_widgets(): void
    {
        $this->actingAs($this->studioUser());

        $gated = fn (): array => [
            ProductionSchedule::canAccess(), ProductionScheduleDay::canAccess(), ProfitAndLoss::canAccess(),
            PromoCodeResource::canAccess(), ExpenseResource::canAccess(),
            UpcomingDecants::canView(), LowStock::canView(), DiscountCost::canView(),
        ];

        $this->assertSame(array_fill(0, 8, true), $gated());

        $this->allOff();

        $this->assertSame(array_fill(0, 8, false), $gated());

        // Hidden is not enough: a typed URL is refused too.
        $this->get(ProductionSchedule::getUrl())->assertForbidden();
        $this->get(ProductionScheduleDay::getUrl(['date' => '2026-10-02']))->assertForbidden();
        $this->get(ProfitAndLoss::getUrl())->assertForbidden();
        $this->get(PromoCodeResource::getUrl('index'))->assertForbidden();
        $this->get(ExpenseResource::getUrl('index'))->assertForbidden();

        // and the menu no longer lists them; core pages still open
        $this->assertSame(
            ['Dashboard', 'Brands', 'Fragrances', 'Orders', 'Payment', 'Design · ဒီဇိုင်း', 'Features', 'Delivery Zones'],
            $this->menu(),
        );
    }

    public function test_stock_and_cost_off_hide_their_fields_but_keep_the_numbers(): void
    {
        $this->actingAs($this->studioUser());
        $product = $this->aventus();
        ShopSetting::current()->update(['modules' => [Modules::PROMO_CODES]]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertOk()
            ->assertFormFieldDoesNotExist('stock_amount')
            ->assertFormFieldDoesNotExist('reference_cost_mmk')
            ->fillForm(['name' => 'Aventus Absolu'])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame('Aventus Absolu', $product->name);
        $this->assertSame(100, $product->stock_amount); // untouched, not blanked
        $this->assertSame(100000, $product->reference_cost_mmk);

        Livewire::test(OrderStats::class)->assertDontSee('Gross margin');
    }

    public function test_per_variant_stock_and_cost_off_keep_each_variants_numbers(): void
    {
        $this->actingAs($this->studioUser());
        ShopSetting::current()->update(['template' => 'clothing', 'modules' => [Modules::PROMO_CODES]]);
        $shirt = Product::create([
            'name' => 'Linen Shirt', 'template' => 'clothing', 'low_stock_threshold' => 3,
            'attributes' => ['material' => 'linen', 'gender' => 'unisex'],
        ]);
        $shirt->variants()->create([
            'options' => ['Size' => 'M', 'Color' => 'Blue'], 'price_mmk' => 25000, 'stock_qty' => 5, 'unit_cost_mmk' => 12000,
        ]);

        Livewire::test(EditProduct::class, ['record' => $shirt->getRouteKey()])
            ->assertOk()
            ->assertFormFieldHidden('variants.record-'.$shirt->variants()->value('id').'.stock_qty')
            ->fillForm(['name' => 'Linen Shirt II'])
            ->call('save')
            ->assertHasNoFormErrors();

        $variant = $shirt->variants()->firstOrFail();
        $this->assertSame([5, 12000, 25000], [$variant->stock_qty, $variant->unit_cost_mmk, $variant->price_mmk]);
        $this->assertSame(3, $shirt->fresh()->low_stock_threshold); // the reorder line survives the save
    }

    public function test_a_product_created_with_stock_off_gets_its_modes_reorder_line(): void
    {
        $this->actingAs($this->studioUser());
        ShopSetting::current()->update(['template' => 'clothing', 'modules' => []]);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Linen Shirt',
                'attributes' => ['material' => 'linen', 'gender' => 'women'],
                'variants' => [
                    ['options' => ['Size' => 'M', 'Color' => 'Blue'], 'price_mmk' => 25000, 'in_stock' => true, 'is_active' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // per variant: 2 pieces, as with stock on — not the column's ml-era 30
        $this->assertSame(2, Product::where('name', 'Linen Shirt')->firstOrFail()->low_stock_threshold);
    }

    // ---- API ----

    public function test_meta_lists_the_enabled_modules(): void
    {
        $this->getJson('/api/v1/decant-please/meta')->assertOk()->assertJsonPath('modules', self::ALL);

        ShopSetting::current()->update(['modules' => [Modules::STOCK]]); // busts the cached /meta

        $this->getJson('/api/v1/decant-please/meta')->assertJsonPath('modules', [Modules::STOCK]);
    }

    public function test_promo_codes_off_refuses_every_code_and_checkout_still_places_the_order(): void
    {
        $product = $this->aventus();
        PromoCode::create(['code' => 'SAVE10', 'type' => PromoType::Percent, 'value' => 10]);
        ShopSetting::current()->update(['modules' => [Modules::STOCK]]);

        $this->postJson('/api/v1/decant-please/orders/validate-promo', [
            'code' => 'SAVE10',
            'items' => [['variant_id' => $this->variantId($product, 5), 'quantity' => 2]],
        ])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('discount_mmk', 0)
            ->assertJsonPath('message', "We couldn't find that code.");

        $response = $this->postJson('/api/v1/decant-please/orders', $this->payload($product, ['promo_code' => 'SAVE10']))
            ->assertCreated()
            ->assertJsonPath('total_mmk', 60000) // full price: 2 × 30,000
            ->assertJsonPath('promo_note', "That code was no longer valid, so it wasn't applied — you can still place this order without it.");

        $order = Order::where('tracking_code', $response->json('tracking_code'))->firstOrFail();
        $this->assertSame(0, $order->discount_mmk);
        $this->assertNull($order->promo_code);
        $this->assertSame(0, PromoCode::firstOrFail()->times_used);
    }

    public function test_the_core_order_loop_works_with_every_module_off(): void
    {
        $product = $this->aventus();
        $this->allOff();

        $code = $this->postJson('/api/v1/decant-please/orders', $this->payload($product))
            ->assertCreated()
            ->assertJsonPath('total_mmk', 60000)
            ->json('tracking_code');

        $order = Order::where('tracking_code', $code)->firstOrFail();
        $order->accept(Carbon::parse('2026-10-03'), Carbon::parse('2026-10-04'));
        $order->markPaid();
        $order->update(['status' => OrderStatus::Prepared]);
        $order->update(['status' => OrderStatus::Delivered]);

        $order->refresh();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame(60000, $order->total_mmk);
        // A module hides screens, never writes: the line still freezes its cost
        // (ceil(100,000 × 5 / 30) = 16,667) and stock still draws down.
        $this->assertSame(16667, $order->items->first()->unit_cost_mmk);
        $this->assertSame(90, $product->fresh()->stock_amount);
    }
}

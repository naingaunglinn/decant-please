<?php

namespace Tests\Feature;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Brand;
use App\Models\Fragrance;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    private Fragrance $allure;   // Chanel (designer): 5ml 30k, 10ml 55k, 30ml 150k out-of-stock, featured
    private Fragrance $aventus;  // Creed (niche): 10ml 120k
    private Fragrance $loveInWhite; // Creed (niche), female: 5ml 60k

    protected function setUp(): void
    {
        parent::setUp();

        $chanel = Brand::create(['name' => 'Chanel', 'type' => 'designer']);
        $creed = Brand::create(['name' => 'Creed', 'type' => 'niche']);
        $hidden = Brand::create(['name' => 'Old House', 'type' => 'designer', 'is_active' => false]);

        $this->allure = $chanel->fragrances()->create([
            'name' => 'Allure Homme Sport', 'concentration' => 'cologne', 'gender' => 'male',
            'notes' => 'Orange, Grapefruit, Musk', 'is_featured' => true,
        ]);
        $this->allure->decantPrices()->createMany([
            ['size_ml' => 5, 'price_mmk' => 30000],
            ['size_ml' => 10, 'price_mmk' => 55000],
            ['size_ml' => 30, 'price_mmk' => 150000, 'in_stock' => false],
        ]);

        $this->aventus = $creed->fragrances()->create([
            'name' => 'Aventus', 'concentration' => 'edp', 'gender' => 'male',
            'notes' => 'Pineapple, Birch',
        ]);
        $this->aventus->decantPrices()->create(['size_ml' => 10, 'price_mmk' => 120000]);

        $this->loveInWhite = $creed->fragrances()->create([
            'name' => 'Love In White', 'concentration' => 'edp', 'gender' => 'female',
        ]);
        $this->loveInWhite->decantPrices()->create(['size_ml' => 5, 'price_mmk' => 60000]);

        // must never appear anywhere below
        $creed->fragrances()->create([
            'name' => 'Green Irish Tweed', 'concentration' => 'edp', 'gender' => 'male', 'is_active' => false,
        ])->decantPrices()->create(['size_ml' => 5, 'price_mmk' => 60000]);
        $hidden->fragrances()->create([
            'name' => 'Ghost Scent', 'concentration' => 'edt', 'gender' => 'unisex',
        ])->decantPrices()->create(['size_ml' => 5, 'price_mmk' => 10000]);
    }

    public function test_brands_lists_active_brands_with_active_fragrance_counts_and_caches(): void
    {
        $response = $this->getJson('/api/v1/brands')->assertOk();

        $response->assertJsonCount(2, 'data'); // Old House hidden
        $creed = collect($response->json('data'))->firstWhere('slug', 'creed');
        $this->assertSame(2, $creed['fragrances_count']); // GIT inactive, not counted

        Brand::create(['name' => 'Dior', 'type' => 'designer']);
        $this->getJson('/api/v1/brands')->assertJsonCount(2, 'data'); // still cached
    }

    public function test_fragrance_list_hides_inactive_and_reports_in_stock_min_price(): void
    {
        $response = $this->getJson('/api/v1/fragrances')->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertEqualsCanonicalizing(['Allure Homme Sport', 'Aventus', 'Love In White'], $names->all());

        $allure = collect($response->json('data'))->firstWhere('slug', 'chanel-allure-homme-sport');
        $this->assertSame(30000, $allure['min_price_mmk']);
        $this->assertSame('30,000 Ks', $allure['min_price_formatted']);
        $this->assertSame([5, 10, 30], array_column($allure['prices'], 'size_ml'));
        $this->assertFalse($allure['prices'][2]['in_stock']);
        $this->assertSame('Chanel', $allure['brand']['name']);
    }

    public function test_fragrance_filters_narrow_the_catalog(): void
    {
        $pluck = fn (string $query) => collect($this->getJson("/api/v1/fragrances?{$query}")->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Love In White'], $pluck('gender=female'));
        $this->assertSame(['Allure Homme Sport'], $pluck('brand=chanel'));
        $this->assertEqualsCanonicalizing(['Aventus', 'Love In White'], $pluck('type=niche'));
        $this->assertEqualsCanonicalizing(['Allure Homme Sport', 'Love In White'], $pluck('size=5'));
        $this->assertSame([], $pluck('size=30')); // 30ml exists but out of stock
        $this->assertSame(['Aventus'], $pluck('min_price=100000'));
        $this->assertSame(['Allure Homme Sport'], $pluck('notes=musk'));
        $this->assertEqualsCanonicalizing(['Aventus', 'Love In White'], $pluck('q=creed'));
        $this->assertSame(['Allure Homme Sport'], $pluck('featured=1'));
        $this->assertSame(['Allure Homme Sport', 'Love In White', 'Aventus'], $pluck('sort=price_asc'));
        $this->assertSame(['Aventus', 'Love In White', 'Allure Homme Sport'], $pluck('sort=price_desc'));
    }

    public function test_fragrance_detail_by_slug_and_404_for_inactive(): void
    {
        $this->getJson('/api/v1/fragrances/chanel-allure-homme-sport')
            ->assertOk()
            ->assertJsonPath('data.name', 'Allure Homme Sport')
            ->assertJsonPath('data.concentration_label', 'Cologne')
            ->assertJsonPath('data.brand.type', 'designer')
            ->assertJsonCount(3, 'data.prices');

        $this->getJson('/api/v1/fragrances/creed-green-irish-tweed')->assertNotFound();
    }

    public function test_meta_returns_filter_options_and_price_bounds(): void
    {
        $this->getJson('/api/v1/meta')
            ->assertOk()
            ->assertJsonPath('price.min', 30000)
            ->assertJsonPath('price.max', 120000)   // GIT & Ghost Scent prices excluded
            ->assertJsonPath('sizes', [5, 10])      // 30ml only exists out of stock
            ->assertJsonFragment(['value' => 'designer', 'label' => 'Designer'])
            ->assertJsonFragment(['value' => 'unisex', 'label' => 'Unisex'])
            ->assertJsonStructure(['social' => ['tiktok_url', 'facebook_url']]);
    }

    public function test_checkout_creates_awaiting_order_and_ignores_smuggled_prices(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->payload([
            'items' => [[
                'fragrance_id' => $this->allure->id,
                'size_ml' => 10,
                'quantity' => 2,
                'unit_price_mmk' => 1, // smuggled — must be ignored
            ]],
        ]))->assertCreated();

        $response->assertJsonStructure(['tracking_code', 'total_mmk', 'total_formatted'])
            ->assertJsonPath('total_mmk', 110000)
            ->assertJsonPath('total_formatted', '110,000 Ks');

        $order = Order::where('tracking_code', $response->json('tracking_code'))->firstOrFail();
        $this->assertSame(OrderStatus::AwaitingConfirmation, $order->status);
        $this->assertSame(OrderSource::Website, $order->order_from);
        $this->assertNull($order->decant_date);
        $this->assertSame(55000, $order->items()->first()->unit_price_mmk);
    }

    public function test_checkout_names_the_offending_item_in_422s(): void
    {
        // out-of-stock size, second item
        $errors = $this->postJson('/api/v1/orders', $this->payload([
            'items' => [
                ['fragrance_id' => $this->allure->id, 'size_ml' => 10, 'quantity' => 1],
                ['fragrance_id' => $this->allure->id, 'size_ml' => 30, 'quantity' => 1],
            ],
        ]))->assertUnprocessable()->json('errors');
        $this->assertSame('30ml of Allure Homme Sport just sold out — pick another size.', $errors['items.1'][0]);

        // inactive fragrance
        $git = Fragrance::where('name', 'Green Irish Tweed')->firstOrFail();
        $errors = $this->postJson('/api/v1/orders', $this->payload([
            'items' => [['fragrance_id' => $git->id, 'size_ml' => 5, 'quantity' => 1]],
        ]))->assertUnprocessable()->json('errors');
        $this->assertSame('That fragrance is no longer available.', $errors['items.0'][0]);

        // unknown fragrance id / unknown size
        $this->postJson('/api/v1/orders', $this->payload([
            'items' => [['fragrance_id' => 999999, 'size_ml' => 5, 'quantity' => 1]],
        ]))->assertUnprocessable();
        $errors = $this->postJson('/api/v1/orders', $this->payload([
            'items' => [['fragrance_id' => $this->allure->id, 'size_ml' => 7, 'quantity' => 1]],
        ]))->assertUnprocessable()->json('errors');
        $this->assertSame('7ml of Allure Homme Sport just sold out — pick another size.', $errors['items.0'][0]);

        $this->assertSame(0, Order::count()); // nothing half-created
    }

    public function test_checkout_honeypot_pretends_success_but_creates_nothing(): void
    {
        $this->postJson('/api/v1/orders', $this->payload(['website' => 'https://spam.example']))
            ->assertCreated()
            ->assertJsonStructure(['tracking_code', 'total_mmk', 'total_formatted']);

        $this->assertSame(0, Order::count());
    }

    public function test_checkout_validates_required_fields(): void
    {
        $this->postJson('/api/v1/orders', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_name', 'phone', 'address', 'items']);
    }

    public function test_tracking_round_trip_and_generic_404s(): void
    {
        $code = $this->postJson('/api/v1/orders', $this->payload())->assertCreated()->json('tracking_code');

        $order = Order::where('tracking_code', $code)->firstOrFail();

        $this->getJson('/api/v1/orders/track?tracking_code='.strtolower($code).'&phone=09-771234561')
            ->assertOk()
            ->assertJsonPath('tracking_code', $code)  // lowercase input normalized
            ->assertJsonPath('order_number', "#{$order->id}")
            ->assertJsonPath('status', 'awaiting_confirmation')
            ->assertJsonPath('status_label', 'Awaiting Confirmation')
            ->assertJsonPath('decant_date', null)
            ->assertJsonPath('rejection_reason', null)
            ->assertJsonPath('customer_name', 'Su Su')
            ->assertJsonPath('phone', '09-771234561')
            ->assertJsonPath('address', 'No. 12, Bahan Township, Yangon')
            ->assertJsonPath('items.0.fragrance_name', 'Chanel — Allure Homme Sport (Cologne)')
            ->assertJsonPath('items.0.size_ml', 10)
            ->assertJsonPath('items.0.unit_price_mmk', 55000)
            ->assertJsonPath('items.0.line_total_mmk', 55000)
            ->assertJsonPath('subtotal_mmk', 55000)
            ->assertJsonPath('delivery_fee_mmk', 0)
            ->assertJsonPath('discount_mmk', 0)
            ->assertJsonPath('deposit_mmk', 0)
            ->assertJsonPath('total_mmk', 55000)
            ->assertJsonPath('total_formatted', '55,000 Ks')
            ->assertJsonStructure(['placed_at']);

        $wrongPhone = $this->getJson("/api/v1/orders/track?tracking_code={$code}&phone=09-000000000")->assertNotFound();
        $wrongCode = $this->getJson('/api/v1/orders/track?tracking_code=WRONGCODE9&phone=09-771234561')->assertNotFound();
        $this->assertSame($wrongPhone->json(), $wrongCode->json()); // identical — no oracle
    }

    public function test_customer_can_cancel_only_while_awaiting_confirmation(): void
    {
        $code = $this->postJson('/api/v1/orders', $this->payload())->assertCreated()->json('tracking_code');
        $pair = ['tracking_code' => $code, 'phone' => '09-771234561'];

        // wrong pair → the same generic 404 tracking uses
        $wrong = $this->postJson('/api/v1/orders/cancel', ['tracking_code' => $code, 'phone' => '09-000000000'])
            ->assertNotFound();
        $this->assertSame(
            $this->getJson('/api/v1/orders/track?tracking_code=WRONGCODE9&phone=x')->json(),
            $wrong->json(),
        );

        // awaiting_confirmation → cancels, returns the updated receipt in place
        $this->postJson('/api/v1/orders/cancel', $pair)
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('subtotal_mmk', 55000);
        $this->assertSame(OrderStatus::Cancelled, Order::where('tracking_code', $code)->firstOrFail()->status);

        // already cancelled → 409, exact copy the receipt shows
        $this->postJson('/api/v1/orders/cancel', $pair)
            ->assertStatus(409)
            ->assertJsonPath('message', "This order's already being prepared — call to cancel or change it.");

        // accepted order → 409, status untouched
        $accepted = Order::newFromCheckout($this->payload());
        $accepted->accept(today()->addDay());
        $this->postJson('/api/v1/orders/cancel', ['tracking_code' => $accepted->tracking_code, 'phone' => '09-771234561'])
            ->assertStatus(409);
        $this->assertSame(OrderStatus::Pending, $accepted->fresh()->status);
    }

    public function test_checkout_rate_limit_is_tight(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/orders', [])->assertUnprocessable();
        }

        $this->postJson('/api/v1/orders', [])->assertStatus(429);
    }

    public function test_tracking_rate_limit_is_tight(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/v1/orders/track?tracking_code=X&phone=Y')->assertNotFound();
        }

        $this->getJson('/api/v1/orders/track?tracking_code=X&phone=Y')->assertStatus(429);

        // buckets are per-endpoint: exhausting tracking must not starve checkout or cancel
        $this->postJson('/api/v1/orders/cancel', ['tracking_code' => 'X', 'phone' => 'Y'])->assertNotFound();
        $this->postJson('/api/v1/orders', [])->assertUnprocessable();
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Su Su',
            'phone' => '09-771234561',
            'address' => 'No. 12, Bahan Township, Yangon',
            'note' => 'Please call before delivery',
            'items' => [['fragrance_id' => $this->allure->id, 'size_ml' => 10, 'quantity' => 1]],
        ];
    }
}

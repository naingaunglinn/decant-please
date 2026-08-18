<?php

namespace Tests\Feature;

use App\Enums\Courier;
use App\Enums\OrderStatus;
use App\Enums\PromoType;
use App\Exceptions\TenantNotSetException;
use App\Filament\Pages\ProductionSchedule;
use App\Filament\Pages\ProductionScheduleDay;
use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Widgets\CourierFloat;
use App\Filament\Widgets\DiscountCost;
use App\Filament\Widgets\LowStock;
use App\Filament\Widgets\OrderStats;
use App\Filament\Widgets\RevenueChart;
use App\Filament\Widgets\TopFragrances;
use App\Filament\Widgets\UpcomingDecants;
use App\Models\Brand;
use App\Models\DeliveryTownship;
use App\Models\DeliveryTownshipCourier;
use App\Models\Expense;
use App\Models\Fragrance;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Shop;
use App\Models\ShopSetting;
use App\Models\User;
use App\Support\MonthlyPnl;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two-shop isolation suite (multi-tenancy design-doc §8). Every assertion checks
 * a COUNT, never mere presence — "returns A's rows" also passes while leaking B's;
 * the Telegram double-send lesson. Model-level cases drive the tenant through
 * TenantContext directly; the surface-level cases hit the real /api/v1/{shop} paths,
 * the /admin/{tenant} routes, and the panel's Livewire tables/widgets.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shopA;

    private Shop $shopB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopA = Shop::factory()->create(['slug' => 'shop-a']);
        $this->shopB = Shop::factory()->create(['slug' => 'shop-b']);
    }

    private function forShop(Shop $shop): void
    {
        app(TenantContext::class)->set($shop);
    }

    private function makeOrder(
        OrderStatus $status = OrderStatus::AwaitingConfirmation,
        int $totalMmk = 10000,
        string $customer = 'Buyer',
    ): Order {
        return Order::create([
            'customer_name' => $customer,
            'phone' => '09-771234561',
            'address' => 'Somewhere, Yangon',
            'order_from' => 'website',
            'status' => $status,
            'total_mmk' => $totalMmk,
        ])->refresh();
    }

    /** One priced 10ml fragrance in the CURRENT shop's catalog (55,000 Ks). */
    private function makeFragrance(): Fragrance
    {
        $brand = Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        $fragrance = $brand->fragrances()->create([
            'name' => 'Allure Homme Sport', 'concentration' => 'cologne', 'gender' => 'male',
        ]);
        $fragrance->decantPrices()->create(['size_ml' => 10, 'price_mmk' => 55000]);

        return $fragrance;
    }

    /** A priced 10ml fragrance under a NAMED brand, in the CURRENT shop's catalog. */
    private function makeNamedFragrance(string $brandName, string $fragranceName): Fragrance
    {
        $brand = Brand::firstOrCreate(['name' => $brandName], ['type' => 'designer']);

        $fragrance = $brand->fragrances()->create([
            'name' => $fragranceName, 'concentration' => 'edp', 'gender' => 'unisex',
        ]);
        $fragrance->decantPrices()->create(['size_ml' => 10, 'price_mmk' => 55000]);

        return $fragrance;
    }

    public function test_catalog_queries_return_only_the_current_shops_rows(): void
    {
        $this->forShop($this->shopA);
        Brand::create(['name' => 'Chanel', 'type' => 'designer']);
        Brand::create(['name' => 'Dior', 'type' => 'designer']);

        $this->forShop($this->shopB);
        Brand::create(['name' => 'Creed', 'type' => 'niche']);

        $this->forShop($this->shopA);
        $this->assertSame(2, Brand::count());
        $this->assertEqualsCanonicalizing(['Chanel', 'Dior'], Brand::pluck('name')->all());

        $this->forShop($this->shopB);
        $this->assertSame(1, Brand::count());
        $this->assertSame(['Creed'], Brand::pluck('name')->all());
    }

    public function test_two_shops_can_each_have_a_brand_named_chanel(): void
    {
        // The global brands.name / brands.slug uniques would kill this — the whole
        // reason they became (shop_id, …) composites (findings Q5/A2).
        $this->forShop($this->shopA);
        $a = Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        $this->forShop($this->shopB);
        $b = Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame('chanel', $a->slug);
        $this->assertSame('chanel', $b->slug); // same slug, different shop — allowed
        $this->assertSame(1, Brand::count());  // B sees exactly one Chanel
    }

    public function test_the_brand_form_accepts_a_name_that_only_another_shop_carries(): void
    {
        // Same discriminating case as the promo form below: a raw ->unique()
        // would let A's catalog block B from stocking "Chanel" at all.
        $this->actingAs($this->studioUser());

        $this->forShop($this->shopA);
        Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        $this->forShop($this->shopB);
        Livewire::test(CreateBrand::class)
            ->fillForm(['name' => 'Chanel', 'type' => 'designer'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Brand::count()); // B sees exactly its own Chanel
    }

    public function test_two_shops_can_run_the_same_promo_code(): void
    {
        // promo_codes.code joined the composite pass late — (shop_id, code), the
        // same reasoning as both-shops-Chanel above.
        $this->forShop($this->shopA);
        $a = PromoCode::create(['code' => 'SUMMER26', 'type' => PromoType::Fixed, 'value' => 5000]);

        $this->forShop($this->shopB);
        $b = PromoCode::create(['code' => 'SUMMER26', 'type' => PromoType::Fixed, 'value' => 1000]);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(1, PromoCode::count()); // B sees exactly its own SUMMER26
    }

    public function test_the_promo_form_accepts_a_code_that_only_another_shop_runs(): void
    {
        // The discriminating case for the form rule: a plain ->unique() queries
        // the table raw and would block B from running a code A already has.
        // scopedUnique goes through the tenant-scoped query, so it must not.
        $this->actingAs($this->studioUser());

        $this->forShop($this->shopA);
        PromoCode::create(['code' => 'SUMMER26', 'type' => PromoType::Fixed, 'value' => 5000]);

        $this->forShop($this->shopB);
        Livewire::test(CreatePromoCode::class)
            ->fillForm([
                'code' => 'SUMMER26',
                'type' => PromoType::Fixed->value,
                'value' => 1000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, PromoCode::count()); // B sees exactly its own SUMMER26
        $this->assertSame(1000, PromoCode::firstOrFail()->value);
    }

    public function test_a_shops_promo_code_is_not_redeemable_in_another_shops_checkout(): void
    {
        // §8 "Two shops, promo codes" — over the real /api/v1/{shop} surface.
        $this->forShop($this->shopA);
        PromoCode::create(['code' => 'ONLYA', 'type' => PromoType::Fixed, 'value' => 5000]);
        $aItem = $this->makeFragrance();

        $this->forShop($this->shopB);
        $bItem = $this->makeFragrance();

        // Positive control: A's code validates under A's own path.
        $this->postJson("/api/v1/{$this->shopA->slug}/orders/validate-promo", [
            'code' => 'ONLYA',
            'items' => [['fragrance_id' => $aItem->id, 'size_ml' => 10, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('valid', true)->assertJsonPath('discount_mmk', 5000);

        // The same code under B's path does not exist — never A's discount in B's cart.
        $this->postJson("/api/v1/{$this->shopB->slug}/orders/validate-promo", [
            'code' => 'ONLYA',
            'items' => [['fragrance_id' => $bItem->id, 'size_ml' => 10, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('valid', false)->assertJsonPath('discount_mmk', 0);
    }

    public function test_tracking_lookup_is_scoped_to_the_shop(): void
    {
        $this->forShop($this->shopA);
        $order = $this->makeOrder();

        $this->forShop($this->shopB);
        $this->assertNull(Order::findByTracking($order->tracking_code, $order->phone));

        $this->forShop($this->shopA);
        $this->assertNotNull(Order::findByTracking($order->tracking_code, $order->phone));
    }

    public function test_tracking_codes_stay_globally_unique_across_shops(): void
    {
        // generateTrackingCode wraps its dedup in withoutTenancy so the exists()
        // check spans every shop — the codes below must all differ (findings Q2).
        $codes = [];

        foreach ([$this->shopA, $this->shopB, $this->shopA, $this->shopB] as $shop) {
            $this->forShop($shop);
            $codes[] = $this->makeOrder()->tracking_code;
        }

        $this->assertCount(4, array_unique($codes));
    }

    public function test_expenses_are_scoped_to_the_shop(): void
    {
        $this->forShop($this->shopA);
        Expense::create(['spent_on' => '2026-08-01', 'category' => 'other', 'amount_mmk' => 1000]);
        Expense::create(['spent_on' => '2026-08-02', 'category' => 'packaging', 'amount_mmk' => 2000]);

        $this->forShop($this->shopB);
        Expense::create(['spent_on' => '2026-08-01', 'category' => 'other', 'amount_mmk' => 5000]);

        $this->forShop($this->shopA);
        $this->assertSame(2, Expense::count());
        $this->assertSame(3000, (int) Expense::sum('amount_mmk'));

        $this->forShop($this->shopB);
        $this->assertSame(1, Expense::count());
        $this->assertSame(5000, (int) Expense::sum('amount_mmk'));
    }

    public function test_delivery_townships_are_scoped_and_cross_shop_ids_do_not_resolve(): void
    {
        $this->forShop($this->shopA);
        $a = DeliveryTownship::create(['region' => 'yangon', 'name' => 'Bahan', 'is_active' => true]);

        $this->forShop($this->shopB);
        $this->assertSame(0, DeliveryTownship::count());
        // The checkout equivalent: serviceable()->find(otherShopId) must return null,
        // so a B checkout can't be routed to / priced by A's township (findings A5).
        $this->assertNull(DeliveryTownship::find($a->id));

        $this->forShop($this->shopA);
        $this->assertSame(1, DeliveryTownship::count());
    }

    public function test_invoice_route_resolves_the_tenant_from_the_url_alone(): void
    {
        // Regression: these routes once registered via authenticatedRoutes(),
        // OUTSIDE the /admin/{tenant} group — IdentifyTenant never ran,
        // TenantContext stayed unset, and the {order} binding threw
        // TenantNotSetException (a 500 on every invoice open in production,
        // masked in tests by the context TestCase presets). The context is
        // cleared here on purpose: the request must succeed on the route's own
        // tenant resolution, nothing else.
        $this->actingAs($this->studioUser());

        $this->forShop($this->shopA);
        $order = $this->makeOrder(OrderStatus::Pending); // fulfillable → invoice renders

        app(TenantContext::class)->set(null);

        $this->get(route('filament.admin.orders.invoice', [
            'tenant' => $this->shopA->slug,
            'order' => $order,
        ]))->assertOk();

        // The route's own lifecycle (IdentifyTenant → TenantSet → listener) is
        // what set the context — proof the binding ran tenant-scoped.
        $this->assertSame($this->shopA->id, app(TenantContext::class)->id());
    }

    public function test_cross_shop_invoice_and_proof_routes_are_generic_404s(): void
    {
        // §8 "Two shops, proof route": B's order id under A's panel URL must
        // never stream bytes — the design's highest-severity line. Status is
        // fulfillable and a proof exists, so a 404 here can only mean the
        // tenant-scoped binding refused the cross-shop id.
        Storage::fake(config('filesystems.proofs_disk'));
        $this->actingAs($this->studioUser());

        $this->forShop($this->shopB);
        $order = $this->makeOrder(OrderStatus::Pending);
        $order->update([
            'payment_proof_path' => UploadedFile::fake()->image('proof.jpg')
                ->store('payment-proofs', config('filesystems.proofs_disk')),
        ]);

        app(TenantContext::class)->set(null);

        $this->get(route('filament.admin.orders.invoice', [
            'tenant' => $this->shopA->slug,
            'order' => $order,
        ]))->assertNotFound();

        $this->get(route('filament.admin.orders.payment-proof', [
            'tenant' => $this->shopA->slug,
            'order' => $order,
        ]))->assertNotFound();
    }

    public function test_public_catalog_api_returns_exact_per_shop_counts(): void
    {
        // §8 "Two shops, catalog" — over the real /api/v1/{shop} paths.
        $this->forShop($this->shopA);
        $this->makeFragrance();
        Brand::firstOrFail()->fragrances()->create([
            'name' => 'Bleu de Chanel', 'concentration' => 'edp', 'gender' => 'male',
        ]);

        $this->forShop($this->shopB);
        $this->makeFragrance();

        $this->getJson("/api/v1/{$this->shopA->slug}/fragrances")
            ->assertOk()->assertJsonPath('meta.total', 2);

        $this->getJson("/api/v1/{$this->shopB->slug}/fragrances")
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_tracking_over_http_is_shop_scoped_and_the_404_stays_generic(): void
    {
        // §8 "Two shops, tracking": A's code+phone under B's path is the same
        // generic 404 as a wrong code — no oracle distinguishing "wrong shop"
        // from "no such order".
        $this->forShop($this->shopA);
        $order = $this->makeOrder();

        $query = http_build_query(['tracking_code' => $order->tracking_code, 'phone' => $order->phone]);

        $this->getJson("/api/v1/{$this->shopA->slug}/orders/track?{$query}")->assertOk();

        $crossShop = $this->getJson("/api/v1/{$this->shopB->slug}/orders/track?{$query}")
            ->assertNotFound();
        $wrongCode = $this->getJson("/api/v1/{$this->shopA->slug}/orders/track?".http_build_query([
            'tracking_code' => 'AAAAAAAAAA', 'phone' => $order->phone,
        ]))->assertNotFound();

        $this->assertSame($wrongCode->json(), $crossShop->json()); // byte-identical bodies
    }

    public function test_admin_order_table_lists_only_the_current_shops_orders(): void
    {
        // §8 "Two shops, admin table" — exact rendered counts, not presence.
        $this->actingAs($this->studioUser());

        $this->forShop($this->shopA);
        $this->makeOrder();
        $this->makeOrder();

        $this->forShop($this->shopB);
        $bOrder = $this->makeOrder();

        $this->forShop($this->shopA);
        Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->assertCountTableRecords(2)
            ->assertCanNotSeeTableRecords([$bOrder]);

        $this->forShop($this->shopB);
        Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->assertCountTableRecords(1);
    }

    public function test_dashboard_stats_count_only_the_current_shop(): void
    {
        // §8 "Two shops, dashboard" — the widgets are custom queries Filament's
        // own tenancy never scopes; the app scope is what isolates them.
        $this->actingAs($this->studioUser());

        $this->forShop($this->shopA);
        $this->makeOrder(OrderStatus::Pending, totalMmk: 70000);

        $this->forShop($this->shopB);
        $this->makeOrder(OrderStatus::Pending, totalMmk: 999999);

        $this->forShop($this->shopA);
        Livewire::test(OrderStats::class)
            ->assertSee('70,000 Ks')       // A's revenue, exactly
            ->assertDontSee('999,999')     // never B's order…
            ->assertDontSee('1,069,999');  // …and never A+B pooled
    }

    public function test_csv_export_contains_zero_other_shop_rows(): void
    {
        // §8 "Two shops, CSV export" — inspect the actual streamed bytes.
        $this->actingAs($this->studioUser());

        $this->forShop($this->shopA);
        $this->makeOrder(customer: 'Alice From Shop A');

        $this->forShop($this->shopB);
        $this->makeOrder(customer: 'Bobby From Shop B');

        $this->forShop($this->shopA);
        $livewire = Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->callTableAction('exportCsv')
            ->assertFileDownloaded('orders-'.now()->format('Y-m-d').'.csv');

        $csv = base64_decode((string) data_get($livewire->effects, 'download.content'));

        $this->assertSame(2, substr_count(trim($csv), "\n") + 1); // header + exactly A's one row
        $this->assertStringContainsString('Alice From Shop A', $csv);
        $this->assertStringNotContainsString('Bobby From Shop B', $csv);
    }

    public function test_profit_and_loss_counts_only_own_orders_and_expenses(): void
    {
        // §8 "Two shops, P&L + expenses": an expense entered in B never moves A's
        // net; order counts stay per-shop (custom Page → app scope, findings A4).
        $this->forShop($this->shopA);
        $this->makeOrder();
        Expense::create(['spent_on' => today(), 'category' => 'marketing', 'amount_mmk' => 3000]);

        $this->forShop($this->shopB);
        $this->makeOrder();
        $this->makeOrder();
        Expense::create(['spent_on' => today(), 'category' => 'marketing', 'amount_mmk' => 5000]);

        $this->forShop($this->shopA);
        $pnlA = MonthlyPnl::for(today()->year, today()->month);
        $this->assertSame(1, $pnlA->totalOrders);
        $this->assertSame(3000, $pnlA->operatingTotalMmk);

        $this->forShop($this->shopB);
        $pnlB = MonthlyPnl::for(today()->year, today()->month);
        $this->assertSame(2, $pnlB->totalOrders);
        $this->assertSame(5000, $pnlB->operatingTotalMmk);
    }

    public function test_delivery_zones_endpoint_is_per_shop_in_content_and_cache(): void
    {
        // §8 "Two shops, delivery zones": content per shop, and editing B's
        // township busts only B's cache key.
        $this->forShop($this->shopA);
        $this->serviceableTownship(fee: 2000, name: 'Bahan');

        $this->forShop($this->shopB);
        $bTownship = $this->serviceableTownship(fee: 3000, name: 'Sanchaung');

        $a = $this->getJson("/api/v1/{$this->shopA->slug}/delivery-zones")->assertOk();
        $this->assertSame(['Bahan'], array_column($a->json('regions.0.townships'), 'name'));
        $this->assertSame(2000, $a->json('regions.0.townships.0.fee_mmk'));

        $b = $this->getJson("/api/v1/{$this->shopB->slug}/delivery-zones")->assertOk();
        $this->assertSame(['Sanchaung'], array_column($b->json('regions.0.townships'), 'name'));
        $this->assertSame(3000, $b->json('regions.0.townships.0.fee_mmk'));

        // Both responses are cached now; a B reprice busts B's key alone.
        $this->assertTrue(Cache::has('api.delivery-zones.'.$this->shopA->slug));
        $this->assertTrue(Cache::has('api.delivery-zones.'.$this->shopB->slug));

        $bTownship->update(['fee_mmk' => 3500]);

        $this->assertTrue(Cache::has('api.delivery-zones.'.$this->shopA->slug));
        $this->assertFalse(Cache::has('api.delivery-zones.'.$this->shopB->slug));
    }

    public function test_checkout_rejects_another_shops_township_id_over_http(): void
    {
        // §8 "Two shops, checkout township": B's township id in A's checkout is a
        // 422 — the scoped serviceable()->find() returns null, so the order is
        // never routed to, or priced by, another shop's zone.
        $this->forShop($this->shopA);
        $aItem = $this->makeFragrance();
        $aTownship = $this->serviceableTownship(fee: 2000, name: 'Bahan');

        $this->forShop($this->shopB);
        $bTownship = $this->serviceableTownship(fee: 9999, name: 'Sanchaung');

        $payload = fn (int $townshipId): array => [
            'customer_name' => 'Su Su',
            'phone' => '09-771234561',
            'delivery_township_id' => $townshipId,
            'address_line' => 'No. 12, Yangon',
            'items' => [['fragrance_id' => $aItem->id, 'size_ml' => 10, 'quantity' => 1]],
        ];

        // Positive control: A's own township checks out at A's fee.
        $this->postJson("/api/v1/{$this->shopA->slug}/orders", $payload($aTownship->id))
            ->assertCreated()
            ->assertJsonPath('delivery_fee_mmk', 2000);

        $this->postJson("/api/v1/{$this->shopA->slug}/orders", $payload($bTownship->id))
            ->assertUnprocessable();
    }

    public function test_two_shops_can_route_the_same_township_through_the_same_courier(): void
    {
        // delivery_township_couriers keeps its (delivery_township_id, courier)
        // unique DELIBERATELY un-widened: township ids are already per-shop, so
        // the pair can never collide across shops and a (shop_id, …) composite
        // would be redundant width. What must hold — and what this pins — is
        // that B mirroring A's geography and courier is legal, while a same-shop
        // duplicate route still dies on the unique.
        $this->forShop($this->shopA);
        $a = DeliveryTownship::create(['region' => 'yangon', 'name' => 'Bahan']);
        $a->couriers()->create(['courier' => Courier::RoyalExpress->value, 'courier_name' => 'Yangon', 'is_available' => true]);

        $this->forShop($this->shopB);
        $b = DeliveryTownship::create(['region' => 'yangon', 'name' => 'Bahan']);
        $b->couriers()->create(['courier' => Courier::RoyalExpress->value, 'courier_name' => 'Yangon', 'is_available' => true]);

        $this->assertSame(1, DeliveryTownshipCourier::count()); // B sees exactly its own route

        $this->expectException(QueryException::class);
        $b->couriers()->create(['courier' => Courier::RoyalExpress->value, 'courier_name' => 'dup', 'is_available' => true]);
    }

    public function test_a_tenant_query_with_no_context_throws_rather_than_leaking(): void
    {
        $this->forShop($this->shopA);
        Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        app(TenantContext::class)->set(null);

        $this->expectException(TenantNotSetException::class);
        Brand::count(); // must throw, NOT return every shop's rows
    }

    public function test_without_tenancy_sees_every_shops_rows(): void
    {
        $this->forShop($this->shopA);
        Brand::create(['name' => 'Chanel', 'type' => 'designer']);
        $this->forShop($this->shopB);
        Brand::create(['name' => 'Dior', 'type' => 'designer']);

        $all = app(TenantContext::class)->withoutTenancy(fn () => Brand::count());
        $this->assertSame(2, $all);
    }

    public function test_meta_cache_key_and_busting_are_per_shop(): void
    {
        Cache::put('api.meta.'.$this->shopA->slug, ['who' => 'A'], 600);
        Cache::put('api.meta.'.$this->shopB->slug, ['who' => 'B'], 600);

        // Saving A's payment settings busts only A's meta key (ShopSetting saved hook).
        $this->forShop($this->shopA);
        ShopSetting::current()->update(['kbzpay_name' => 'Daw Mya']);

        $this->assertFalse(Cache::has('api.meta.'.$this->shopA->slug));
        $this->assertTrue(Cache::has('api.meta.'.$this->shopB->slug));
    }

    public function test_without_tenancy_is_a_rare_audited_escape_hatch(): void
    {
        // The design budgets withoutTenancy() to a handful of call sites (§8 ledger);
        // guard against proliferation. Counts CALLS (->withoutTenancy(), not the
        // definition) across app/.
        $count = 0;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with((string) $file->getFilename(), '.php')) {
                $count += substr_count((string) file_get_contents($file->getPathname()), '->withoutTenancy(');
            }
        }

        $this->assertLessThanOrEqual(5, $count);
    }

    public function test_brands_and_meta_endpoints_are_per_shop_in_content(): void
    {
        // Phase 4 case 1, the endpoints the fragrances-count case doesn't reach:
        // /brands rows and /meta's catalog-derived figures are the current shop's.
        $this->forShop($this->shopA);
        $this->makeFragrance(); // Chanel, one 10ml price
        Brand::create(['name' => 'Dior', 'type' => 'designer']);

        $this->forShop($this->shopB);
        $this->makeFragrance()->decantPrices()->create(['size_ml' => 30, 'price_mmk' => 99000]);

        $a = $this->getJson("/api/v1/{$this->shopA->slug}/brands")->assertOk();
        $this->assertEqualsCanonicalizing(['Chanel', 'Dior'], array_column($a->json('data'), 'name'));

        $b = $this->getJson("/api/v1/{$this->shopB->slug}/brands")->assertOk();
        $this->assertSame(['Chanel'], array_column($b->json('data'), 'name'));

        $this->getJson("/api/v1/{$this->shopA->slug}/meta")->assertOk()
            ->assertJsonPath('sizes', [10])
            ->assertJsonPath('price.max', 55000);
        $this->getJson("/api/v1/{$this->shopB->slug}/meta")->assertOk()
            ->assertJsonPath('sizes', [10, 30])
            ->assertJsonPath('price.max', 99000);
    }

    public function test_a_shared_fragrance_slug_resolves_to_each_shops_own_row(): void
    {
        // Phase 4 case 2: both shops stock the same fragrance, so both carry the
        // same slug — and /{shop}/fragrances/{slug} must resolve WITHIN the shop.
        $this->forShop($this->shopA);
        $a = $this->makeFragrance();

        $this->forShop($this->shopB);
        $b = $this->makeFragrance();

        $this->assertSame($a->slug, $b->slug); // the (shop_id, slug) composite at work

        $this->getJson("/api/v1/{$this->shopA->slug}/fragrances/{$a->slug}")
            ->assertOk()->assertJsonPath('data.id', $a->id);
        $this->getJson("/api/v1/{$this->shopB->slug}/fragrances/{$b->slug}")
            ->assertOk()->assertJsonPath('data.id', $b->id);
    }

    public function test_cancel_and_proof_upload_under_the_wrong_shop_are_the_same_generic_404(): void
    {
        // Phase 4 case 4: the two mutating public endpoints answer a cross-slug
        // pair exactly like an unknown code — byte-identical, nothing persisted.
        Storage::fake(config('filesystems.proofs_disk'));

        $this->forShop($this->shopA);
        $order = $this->makeOrder(); // awaiting_confirmation — cancellable in its own shop
        $pair = ['tracking_code' => $order->tracking_code, 'phone' => $order->phone];

        $wrongCode = $this->postJson("/api/v1/{$this->shopA->slug}/orders/cancel", [
            'tracking_code' => 'AAAAAAAAAA', 'phone' => $order->phone,
        ])->assertNotFound();

        $crossCancel = $this->postJson("/api/v1/{$this->shopB->slug}/orders/cancel", $pair)
            ->assertNotFound();
        $this->assertSame($wrongCode->json(), $crossCancel->json());

        $crossProof = $this->post(
            "/api/v1/{$this->shopB->slug}/orders/payment-proof",
            $pair + ['proof' => UploadedFile::fake()->image('slip.jpg')],
            ['Accept' => 'application/json'],
        )->assertNotFound();
        $this->assertSame($wrongCode->json(), $crossProof->json());

        // the cross-shop attempts changed nothing: no object stored, order untouched
        Storage::disk(config('filesystems.proofs_disk'))->assertDirectoryEmpty('/');
        $this->forShop($this->shopA);
        $this->assertSame(OrderStatus::AwaitingConfirmation, $order->fresh()->status);
    }

    public function test_the_same_promo_code_string_evaluates_against_the_requesting_shops_row(): void
    {
        // Phase 4 case 5, over HTTP: one code string, two shops, two values —
        // each storefront gets its own shop's discount, never the other's.
        $this->forShop($this->shopA);
        PromoCode::create(['code' => 'SUMMER26', 'type' => PromoType::Fixed, 'value' => 5000]);
        $aItem = $this->makeFragrance();

        $this->forShop($this->shopB);
        PromoCode::create(['code' => 'SUMMER26', 'type' => PromoType::Fixed, 'value' => 1000]);
        $bItem = $this->makeFragrance();

        $this->postJson("/api/v1/{$this->shopA->slug}/orders/validate-promo", [
            'code' => 'SUMMER26',
            'items' => [['fragrance_id' => $aItem->id, 'size_ml' => 10, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('valid', true)->assertJsonPath('discount_mmk', 5000);

        $this->postJson("/api/v1/{$this->shopB->slug}/orders/validate-promo", [
            'code' => 'SUMMER26',
            'items' => [['fragrance_id' => $bItem->id, 'size_ml' => 10, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('valid', true)->assertJsonPath('discount_mmk', 1000);
    }

    public function test_a_shop_confined_user_cannot_reach_another_shops_panel_or_files(): void
    {
        // Phase 4 case 6, over HTTP: membership is enforced by IdentifyTenant on
        // the request itself, and the answer is a generic 404 — including for the
        // file-streaming routes, before a byte moves.
        $this->forShop($this->shopB);
        $bOrder = $this->makeOrder(OrderStatus::Pending);

        $owner = User::create([
            'name' => 'Owner A',
            'email' => 'owner@shop-a.test',
            'password' => 'secret-password',
            'is_studio' => false,
        ]);
        $owner->shops()->attach($this->shopA);
        $this->actingAs($owner);

        $this->get("/admin/{$this->shopB->slug}")->assertNotFound();
        $this->get(route('filament.admin.orders.invoice', [
            'tenant' => $this->shopB->slug, 'order' => $bOrder,
        ]))->assertNotFound();
        $this->get(route('filament.admin.orders.payment-proof', [
            'tenant' => $this->shopB->slug, 'order' => $bOrder,
        ]))->assertNotFound();

        // positive control: their own shop opens normally
        $this->get("/admin/{$this->shopA->slug}")->assertOk();
    }

    public function test_revenue_chart_sums_only_the_current_shops_orders(): void
    {
        // Phase 4 case 7 (RevenueChart): the dataset is read directly so the
        // assertion is an exact figure, not "some chart rendered".
        $this->actingAs($this->studioUser());

        $this->forShop($this->shopA);
        $this->makeOrder(OrderStatus::Pending, totalMmk: 70000);

        $this->forShop($this->shopB);
        $this->makeOrder(OrderStatus::Pending, totalMmk: 999999);

        $this->forShop($this->shopA);
        $chart = Livewire::test(RevenueChart::class)->instance();
        $data = (new \ReflectionMethod($chart, 'getData'))->invoke($chart);
        $this->assertSame(70000, array_sum($data['datasets'][0]['data']));

        $this->forShop($this->shopB);
        $chart = Livewire::test(RevenueChart::class)->instance();
        $data = (new \ReflectionMethod($chart, 'getData'))->invoke($chart);
        $this->assertSame(999999, array_sum($data['datasets'][0]['data']));
    }

    public function test_top_fragrances_and_low_stock_rank_only_the_current_shops_catalog(): void
    {
        // Phase 4 case 7 (the catalog-side table widgets) — exact row counts.
        $this->actingAs($this->studioUser());

        $this->forShop($this->shopA);
        $aFragrance = $this->makeNamedFragrance('Chanel', 'Allure Homme Sport');
        $aFragrance->update(['stock_ml' => 2, 'low_stock_threshold_ml' => 5]);
        $this->makeOrder(OrderStatus::Pending)->items()->create([
            'fragrance_id' => $aFragrance->id, 'fragrance_name_snapshot' => 'Chanel Allure Homme Sport',
            'size_ml' => 10, 'unit_price_mmk' => 55000, 'quantity' => 3,
        ]);

        $this->forShop($this->shopB);
        $bFragrance = $this->makeNamedFragrance('Dior', 'Sauvage');
        $bFragrance->update(['stock_ml' => 1, 'low_stock_threshold_ml' => 5]);
        $this->makeOrder(OrderStatus::Pending)->items()->create([
            'fragrance_id' => $bFragrance->id, 'fragrance_name_snapshot' => 'Dior Sauvage',
            'size_ml' => 10, 'unit_price_mmk' => 55000, 'quantity' => 9,
        ]);

        $this->forShop($this->shopA);
        Livewire::test(TopFragrances::class)
            ->assertCountTableRecords(1)
            ->assertSee('Allure Homme Sport')
            ->assertDontSee('Sauvage');
        Livewire::test(LowStock::class)
            ->assertCountTableRecords(1)
            ->assertSee('Allure Homme Sport')
            ->assertDontSee('Sauvage');
    }

    public function test_upcoming_decants_lists_only_the_current_shops_orders(): void
    {
        // Phase 4 case 7 (UpcomingDecants) — an exact rendered count.
        $this->actingAs($this->studioUser());

        $this->forShop($this->shopA);
        $this->makeOrder(OrderStatus::Pending, customer: 'Alice From Shop A')
            ->update(['decant_date' => today()->addDay()]);

        $this->forShop($this->shopB);
        $this->makeOrder(OrderStatus::Pending, customer: 'Bobby From Shop B')
            ->update(['decant_date' => today()->addDay()]);

        $this->forShop($this->shopA);
        Livewire::test(UpcomingDecants::class)
            ->assertCountTableRecords(1)
            ->assertSee('Alice From Shop A')
            ->assertDontSee('Bobby From Shop B');
    }

    public function test_courier_float_and_discount_cost_report_only_the_current_shops_money(): void
    {
        // Phase 4 case 7 (the v19/#75/#77 money widgets): PHP sums over a scoped
        // fetch — never B's figure, never the pooled figure.
        $this->actingAs($this->studioUser());

        $this->forShop($this->shopA);
        $this->makeOrder(OrderStatus::Pending)
            ->update(['handed_to_courier_at' => today(), 'courier_carrying_mmk' => 70000]);
        $this->makeOrder(OrderStatus::Pending)
            ->update(['discount_mmk' => 4000, 'promo_code' => 'ONLYA']);

        $this->forShop($this->shopB);
        $this->makeOrder(OrderStatus::Pending)
            ->update(['handed_to_courier_at' => today(), 'courier_carrying_mmk' => 999999]);
        $this->makeOrder(OrderStatus::Pending)
            ->update(['discount_mmk' => 8888, 'promo_code' => 'ONLYB']);

        $this->forShop($this->shopA);
        Livewire::test(CourierFloat::class)
            ->assertSee('70,000 Ks')
            ->assertDontSee('999,999')     // never B's float…
            ->assertDontSee('1,069,999');  // …and never A+B pooled
        Livewire::test(DiscountCost::class)
            ->assertSee('ONLYA')
            ->assertSee('4,000 Ks')
            ->assertDontSee('ONLYB')
            ->assertDontSee('8,888');
    }

    public function test_production_schedule_feed_and_day_page_contain_none_of_the_other_shops_vials(): void
    {
        // Phase 4 case 8: both schedule surfaces read Order::productionScheduleFor,
        // and both must aggregate only the current shop — exact vial counts.
        $this->actingAs($this->studioUser());
        $date = '2026-09-01';

        $this->forShop($this->shopA);
        $aFragrance = $this->makeNamedFragrance('Chanel', 'Allure Homme Sport');
        $aOrder = $this->makeOrder(OrderStatus::Pending);
        $aOrder->update(['decant_date' => $date]);
        $aOrder->items()->create([
            'fragrance_id' => $aFragrance->id, 'fragrance_name_snapshot' => 'Chanel Allure Homme Sport',
            'size_ml' => 10, 'unit_price_mmk' => 55000, 'quantity' => 2,
        ]);

        $this->forShop($this->shopB);
        $bFragrance = $this->makeNamedFragrance('Dior', 'Sauvage');
        $bOrder = $this->makeOrder(OrderStatus::Pending);
        $bOrder->update(['decant_date' => $date]);
        $bOrder->items()->create([
            'fragrance_id' => $bFragrance->id, 'fragrance_name_snapshot' => 'Dior Sauvage',
            'size_ml' => 10, 'unit_price_mmk' => 55000, 'quantity' => 5,
        ]);

        $this->forShop($this->shopA);
        $events = (new ProductionSchedule)->calendarEvents($date, '2026-09-02');
        $this->assertCount(1, $events);
        $this->assertSame('2 vials', $events[0]['title']); // exactly A's — never 7 pooled

        Livewire::test(ProductionScheduleDay::class, ['date' => $date])
            ->assertSee('Chanel — Allure Homme Sport')
            ->assertSee('× 2')
            ->assertDontSee('Dior — Sauvage')
            ->assertDontSee('× 5');

        $this->forShop($this->shopB);
        $this->assertSame('5 vials', (new ProductionSchedule)->calendarEvents($date, '2026-09-02')[0]['title']);
    }

    public function test_the_brands_cache_between_two_shops_requests_stays_per_shop(): void
    {
        // Phase 4 case 10 — the discriminating case for an unkeyed cache key.
        // A's request warms the cache first; B's request follows in the SAME
        // process with the cache deliberately NOT cleared between the two.
        // Clearing it here would let an unkeyed 'api.brands' key pass this test
        // by never being read twice — the whole leak is the second read.
        $this->forShop($this->shopA);
        Brand::create(['name' => 'Chanel', 'type' => 'designer']);
        Brand::create(['name' => 'Dior', 'type' => 'designer']);

        $this->forShop($this->shopB);
        Brand::create(['name' => 'Creed', 'type' => 'niche']);

        $a = $this->getJson("/api/v1/{$this->shopA->slug}/brands")->assertOk();
        $this->assertEqualsCanonicalizing(['Chanel', 'Dior'], array_column($a->json('data'), 'name'));

        // no Cache::flush() here — that is the point
        $b = $this->getJson("/api/v1/{$this->shopB->slug}/brands")->assertOk();
        $this->assertSame(['Creed'], array_column($b->json('data'), 'name'));

        // and A's payload is still A's when its cached copy is read back
        $again = $this->getJson("/api/v1/{$this->shopA->slug}/brands")->assertOk();
        $this->assertEqualsCanonicalizing(['Chanel', 'Dior'], array_column($again->json('data'), 'name'));
    }

    public function test_fresh_start_scoped_to_one_shop_leaves_the_other_shops_data_and_proof_files(): void
    {
        // Phase 4 case 11: resetting A must not touch B's rows OR B's proof
        // objects. B's proof sits at a pre-step-32 UNPREFIXED path on purpose —
        // the regression this pins is the shared-directory wipe, which deleted
        // every shop's files however they were prefixed.
        Storage::fake(config('filesystems.proofs_disk'));
        $disk = Storage::disk(config('filesystems.proofs_disk'));

        $this->forShop($this->shopA);
        $this->makeFragrance();
        $aPath = UploadedFile::fake()->image('a.jpg')
            ->store('shops/'.$this->shopA->id.'/payment-proofs', config('filesystems.proofs_disk'));
        $this->makeOrder()->update(['payment_proof_path' => $aPath]);

        $this->forShop($this->shopB);
        $this->makeFragrance();
        $bPath = UploadedFile::fake()->image('b.jpg')
            ->store('payment-proofs', config('filesystems.proofs_disk')); // old-era path
        $this->makeOrder()->update(['payment_proof_path' => $bPath]);

        $this->artisan('decant:fresh-start', ['--shop' => $this->shopA->slug, '--force' => true])
            ->assertSuccessful();

        $this->forShop($this->shopA);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Fragrance::count());
        $disk->assertMissing($aPath);

        $this->forShop($this->shopB);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Fragrance::count());
        $disk->assertExists($bPath);
    }

    public function test_fresh_start_refuses_without_a_resolved_shop(): void
    {
        // Phase 4 case 11, second half: no ambient tenant and no --shop is a
        // refusal, never a whichever-shop-happens-to-be-first wipe.
        $this->forShop($this->shopA);
        $this->makeOrder();

        app(TenantContext::class)->set(null);

        $this->artisan('decant:fresh-start', ['--force' => true])->assertFailed();

        $this->forShop($this->shopA);
        $this->assertSame(1, Order::count());
    }

    public function test_rate_limiter_buckets_carry_the_shop(): void
    {
        // Step 32: same IP, different shop, different budget — carrier NAT puts
        // many customers of many shops behind one IP.
        $limiter = RateLimiter::limiter('checkout');
        $request = Request::create('/api/v1/shop-a/orders', 'POST');

        $this->forShop($this->shopA);
        $aKey = $limiter($request)->key;

        $this->forShop($this->shopB);
        $bKey = $limiter($request)->key;

        $this->assertStringContainsString('shop-a', $aKey);
        $this->assertStringContainsString('shop-b', $bKey);
        $this->assertNotSame($aKey, $bKey);
    }

    public function test_customer_proof_uploads_land_under_the_shops_own_prefix(): void
    {
        // Step 32: new storage objects carry shops/{id}/ so per-shop archive and
        // delete stay surgical. (Pre-existing objects are not migrated — the
        // stored path is what every reader uses, so both eras keep working.)
        Storage::fake(config('filesystems.proofs_disk'));

        $this->forShop($this->shopA);
        $order = $this->makeOrder();

        $this->post("/api/v1/{$this->shopA->slug}/orders/payment-proof", [
            'tracking_code' => $order->tracking_code,
            'phone' => $order->phone,
            'proof' => UploadedFile::fake()->image('slip.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $order->fresh()->payment_proof_path;
        $this->assertStringStartsWith('shops/'.$this->shopA->id.'/payment-proofs/', $path);
        Storage::disk(config('filesystems.proofs_disk'))->assertExists($path);
    }
}

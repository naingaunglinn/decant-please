<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Exceptions\TenantNotSetException;
use App\Models\Brand;
use App\Models\DeliveryTownship;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopSetting;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The two-shop isolation suite (multi-tenancy Step 23 §8). Every assertion checks a
 * COUNT, never mere presence — "returns A's rows" also passes while leaking B's; the
 * Telegram double-send lesson. In Step 23 there are no {shop} URLs yet, so the tenant
 * is driven through TenantContext directly; Step 24 re-points these at real paths.
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

    private function makeOrder(): Order
    {
        return Order::create([
            'customer_name' => 'Buyer',
            'phone' => '09-771234561',
            'address' => 'Somewhere, Yangon',
            'order_from' => 'website',
            'status' => OrderStatus::AwaitingConfirmation,
            'total_mmk' => 10000,
        ])->refresh();
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
}

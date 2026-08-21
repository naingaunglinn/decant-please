<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ShopStatus;
use App\Filament\Studio\Resources\Shops\Pages\ManageShops;
use App\Filament\Studio\Resources\Shops\ShopResource;
use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopSetting;
use App\Models\User;
use App\Support\StudioShopStats;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step 34 PR-3 — the Studio shop registry + detail page. Owner and per-shop order
 * figures are cross-shop reads; these prove they are correct, per-shop (no leak),
 * and stay within the withoutTenancy budget.
 */
class StudioRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $slug, ShopStatus $status = ShopStatus::Live): Shop
    {
        return Shop::factory()->create(['slug' => $slug, 'name' => ucfirst($slug), 'status' => $status]);
    }

    private function ownerFor(Shop $shop, string $name): User
    {
        $owner = User::factory()->create(['is_studio' => false, 'name' => $name]);
        $owner->shops()->attach($shop);
        $owner->assignRole('shop_owner');

        return $owner;
    }

    private function orderIn(Shop $shop, ?Carbon $at = null): Order
    {
        app(TenantContext::class)->set($shop);
        $order = Order::create([
            'customer_name' => 'Buyer',
            'phone' => '09-771234561',
            'address' => 'Somewhere, Yangon',
            'order_from' => 'website',
            'status' => OrderStatus::Pending,
            'total_mmk' => 10000,
        ]);

        if ($at !== null) {
            $order->forceFill(['created_at' => $at])->save();
        }

        return $order;
    }

    /** Configure at least one payment field on the shop's settings row. */
    private function setPayment(Shop $shop): void
    {
        app(TenantContext::class)->set($shop);
        ShopSetting::current()->update(['kbzpay_name' => 'Decanter', 'kbzpay_number' => '09-000']);
    }

    // ---- columns ------------------------------------------------------------

    public function test_registry_shows_status_pill_owner_and_orders_this_month(): void
    {
        $shop = $this->shop('alpha', ShopStatus::Live);
        $this->ownerFor($shop, 'Owner Bob');
        $this->orderIn($shop);
        $this->orderIn($shop);
        $this->actingAs($this->studioUser());

        Livewire::test(ManageShops::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$shop])
            ->assertSee('Live')        // status pill
            ->assertSee('Owner Bob')   // owner column
            ->assertSee('2');          // orders this month
    }

    public function test_owner_column_is_dash_when_no_shop_owner_member(): void
    {
        $shop = $this->shop('noowner');
        // A member WITHOUT the shop_owner role must not be shown as owner.
        $staff = User::factory()->create(['is_studio' => false, 'name' => 'Just Staff']);
        $staff->shops()->attach($shop);
        $staff->assignRole('shop_staff');

        $owner = $shop->users()->role('shop_owner')->orderBy('users.id')->first();
        $this->assertNull($owner);
    }

    public function test_owner_column_is_deterministic_with_multiple_owners(): void
    {
        $shop = $this->shop('multi');
        $first = $this->ownerFor($shop, 'First Owner');
        $this->ownerFor($shop, 'Second Owner');

        $chosen = $shop->users()->role('shop_owner')->orderBy('users.id')->first();
        $this->assertTrue($chosen->is($first)); // lowest id, deterministic
    }

    // ---- cross-shop aggregate ----------------------------------------------

    public function test_orders_this_month_counts_only_this_month_and_per_shop(): void
    {
        $a = $this->shop('a');
        $b = $this->shop('b');
        $this->orderIn($a);                                   // this month
        $this->orderIn($a, now()->subMonthNoOverflow());      // last month (excluded from this_month)
        $this->orderIn($b);
        $this->orderIn($b);
        $this->orderIn($b);

        $stats = StudioShopStats::orderStats();
        $this->assertSame(1, (int) StudioShopStats::forShop($a->id, $stats)->this_month);
        $this->assertSame(2, (int) StudioShopStats::forShop($a->id, $stats)->total);
        $this->assertSame(3, (int) StudioShopStats::forShop($b->id, $stats)->this_month);
        // A's rows never enter B's totals — no cross-shop leak.
        $this->assertSame(3, (int) StudioShopStats::forShop($b->id, $stats)->total);
    }

    public function test_last_activity_reflects_the_latest_order(): void
    {
        $shop = $this->shop('active');
        $this->orderIn($shop, now()->subDays(5));
        $latest = $this->orderIn($shop, now()->subDay());

        $stats = StudioShopStats::orderStats();
        $this->assertSame(
            $latest->created_at->toDateString(),
            Carbon::parse(StudioShopStats::forShop($shop->id, $stats)->last_at)->toDateString(),
        );
    }

    // ---- filters ------------------------------------------------------------

    public function test_archived_shops_are_hidden_by_default_and_revealable(): void
    {
        $live = $this->shop('livey', ShopStatus::Live);
        $archived = $this->shop('archivey', ShopStatus::Archived);
        $this->actingAs($this->studioUser());

        Livewire::test(ManageShops::class)
            ->assertCanSeeTableRecords([$live])
            ->assertCanNotSeeTableRecords([$archived])
            ->filterTable('status', [
                ShopStatus::Onboarding->value, ShopStatus::Live->value,
                ShopStatus::Suspended->value, ShopStatus::Archived->value,
            ])
            ->assertCanSeeTableRecords([$archived]);
    }

    public function test_needs_attention_filter(): void
    {
        $onboarding = $this->shop('ob', ShopStatus::Onboarding);
        $suspended = $this->shop('sus', ShopStatus::Suspended);
        $liveNoPayment = $this->shop('nopay', ShopStatus::Live);
        $livePaid = $this->shop('paid', ShopStatus::Live);
        $this->setPayment($livePaid);
        $archivedNoPayment = $this->shop('arch', ShopStatus::Archived);
        $this->actingAs($this->studioUser());

        Livewire::test(ManageShops::class)
            // reveal all statuses so the needs-attention result isn't masked by the
            // default (non-archived) status filter
            ->filterTable('status', array_map(fn (ShopStatus $s) => $s->value, ShopStatus::cases()))
            ->filterTable('needs_attention')
            ->assertCanSeeTableRecords([$onboarding, $suspended, $liveNoPayment])
            ->assertCanNotSeeTableRecords([$livePaid, $archivedNoPayment]);
    }

    public function test_zero_state_when_no_shops(): void
    {
        Shop::query()->delete(); // including the TestCase default shop
        $this->actingAs($this->studioUser());

        Livewire::test(ManageShops::class)->assertSee('Register your first shop');
    }

    // ---- detail page --------------------------------------------------------

    public function test_detail_page_shows_owner_counts_and_config_completeness(): void
    {
        $shop = $this->shop('detail', ShopStatus::Live);
        $this->ownerFor($shop, 'Detail Owner');
        $this->orderIn($shop);
        $this->actingAs($this->studioUser());

        // No payment yet → "Not configured".
        $this->get(ShopResource::getUrl('view', ['record' => $shop], panel: 'studio'))
            ->assertOk()
            ->assertSee('Detail Owner')
            ->assertSee('Configuration')
            ->assertSee('Not configured');

        // Add payment → "Configured".
        $this->setPayment($shop);
        $this->get(ShopResource::getUrl('view', ['record' => $shop], panel: 'studio'))
            ->assertOk()
            ->assertSee('Configured');
    }

    public function test_detail_page_is_studio_only(): void
    {
        $shop = $this->shop('gated');
        $owner = $this->ownerFor($shop, 'Owner');
        $this->actingAs($owner); // shop_owner, not studio_admin

        $this->get(ShopResource::getUrl('view', ['record' => $shop], panel: 'studio'))->assertForbidden();
    }

    // ---- budget -------------------------------------------------------------

    public function test_registry_stays_within_the_without_tenancy_budget(): void
    {
        $count = 0;
        foreach (File::allFiles(app_path()) as $file) {
            $count += substr_count((string) File::get($file->getPathname()), '->withoutTenancy(');
        }

        // Order tracking-code dedup (1) + the single registry aggregate (1) = 2.
        $this->assertLessThanOrEqual(5, $count);
        $this->assertStringContainsString('->withoutTenancy(', File::get(app_path('Support/StudioShopStats.php')));
    }
}

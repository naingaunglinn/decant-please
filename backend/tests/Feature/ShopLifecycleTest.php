<?php

namespace Tests\Feature;

use App\Enums\ShopStatus;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Step 34 §1 — shop lifecycle. `status` (onboarding/live/suspended/archived)
 * replaces the overloaded `is_active`; only `live` is served, and `is_active`
 * survives as a derived read-only accessor.
 */
class ShopLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public static function nonLiveStatuses(): array
    {
        return [
            'onboarding' => [ShopStatus::Onboarding],
            'suspended' => [ShopStatus::Suspended],
            'archived' => [ShopStatus::Archived],
        ];
    }

    public function test_only_live_is_active_and_scope_active_returns_only_live(): void
    {
        $live = Shop::factory()->create(['slug' => 'live-shop', 'status' => ShopStatus::Live]);
        $onboarding = Shop::factory()->create(['slug' => 'onboarding-shop', 'status' => ShopStatus::Onboarding]);
        $suspended = Shop::factory()->create(['slug' => 'suspended-shop', 'status' => ShopStatus::Suspended]);
        $archived = Shop::factory()->create(['slug' => 'archived-shop', 'status' => ShopStatus::Archived]);

        $this->assertTrue($live->is_active);
        $this->assertFalse($onboarding->is_active);
        $this->assertFalse($suspended->is_active);
        $this->assertFalse($archived->is_active);

        // scopeActive returns ONLY live (plus the TestCase default shop, also live).
        $activeSlugs = Shop::query()->active()->pluck('slug');
        $this->assertTrue($activeSlugs->contains('live-shop'));
        $this->assertFalse($activeSlugs->contains('onboarding-shop'));
        $this->assertFalse($activeSlugs->contains('suspended-shop'));
        $this->assertFalse($activeSlugs->contains('archived-shop'));
    }

    public function test_is_active_accessor_is_read_only(): void
    {
        $shop = Shop::factory()->create(['status' => ShopStatus::Onboarding]);

        // Setting the derived accessor is a no-op — status is the state.
        $shop->is_active = true;
        $shop->save();

        $this->assertSame(ShopStatus::Onboarding, $shop->fresh()->status);
        $this->assertFalse($shop->fresh()->is_active);
    }

    #[DataProvider('nonLiveStatuses')]
    public function test_the_public_api_rejects_a_non_live_shop(ShopStatus $status): void
    {
        Shop::factory()->create(['slug' => 'dormant', 'status' => $status]);

        $this->getJson('/api/v1/dormant/brands')->assertNotFound();
    }

    public function test_the_public_api_serves_a_live_shop(): void
    {
        Shop::factory()->create(['slug' => 'awake', 'status' => ShopStatus::Live]);

        $this->getJson('/api/v1/awake/brands')->assertOk();
    }

    #[DataProvider('nonLiveStatuses')]
    public function test_the_host_resolver_rejects_a_non_live_shop(ShopStatus $status): void
    {
        $shop = Shop::factory()->create(['slug' => 'dormant', 'status' => $status]);
        $shop->domains()->create(['host' => 'dormant.example', 'is_primary' => true, 'verified_at' => now()]);

        $this->getJson('/api/v1/_storefront/host/dormant.example')->assertNotFound();
    }

    public function test_suspension_requires_a_reason_and_records_the_actor(): void
    {
        $actor = User::factory()->create(['is_studio' => true]);
        $shop = Shop::factory()->create(['status' => ShopStatus::Live]);

        $shop->suspend($actor, 'Non-payment');
        $shop->refresh();

        $this->assertSame(ShopStatus::Suspended, $shop->status);
        $this->assertSame('Non-payment', $shop->suspended_reason);
        $this->assertNotNull($shop->suspended_at);
        $this->assertTrue($shop->suspendedBy->is($actor));
    }

    public function test_suspension_rejects_a_blank_reason(): void
    {
        $actor = User::factory()->create(['is_studio' => true]);
        $shop = Shop::factory()->create(['status' => ShopStatus::Live]);

        $this->expectException(\InvalidArgumentException::class);

        $shop->suspend($actor, '   ');
    }

    public function test_activate_brings_a_suspended_shop_live_and_clears_suspension(): void
    {
        $actor = User::factory()->create(['is_studio' => true]);
        $shop = Shop::factory()->create(['status' => ShopStatus::Onboarding]);
        $shop->suspend($actor, 'temporary');

        $shop->activate();
        $shop->refresh();

        $this->assertSame(ShopStatus::Live, $shop->status);
        $this->assertNull($shop->suspended_reason);
        $this->assertNull($shop->suspended_at);
        $this->assertNull($shop->suspended_by);
        $this->assertTrue($shop->is_active);
    }

    public function test_archive_is_a_transition_to_the_archived_state(): void
    {
        $shop = Shop::factory()->create(['status' => ShopStatus::Suspended]);

        $shop->archive();

        $this->assertSame(ShopStatus::Archived, $shop->fresh()->status);
        $this->assertFalse($shop->fresh()->is_active);
    }
}

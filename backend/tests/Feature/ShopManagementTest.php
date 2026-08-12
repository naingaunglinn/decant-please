<?php

namespace Tests\Feature;

use App\Filament\Resources\Shops\ShopResource;
use App\Models\Shop;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-tenancy Step 25a: the studio operator manages shops and switches between
 * them; a shop owner is confined to their own. Tenancy membership is proven at the
 * model layer (getTenants/canAccessTenant) and the resource gate.
 */
class ShopManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_studio_user_can_operate_every_shop(): void
    {
        $panel = Filament::getPanel('admin');

        $studio = User::factory()->create(['is_studio' => true]);
        $a = Shop::factory()->create();
        $b = Shop::factory()->create();

        $tenants = $studio->getTenants($panel);
        $this->assertTrue($tenants->contains($a));
        $this->assertTrue($tenants->contains($b));
        $this->assertTrue($studio->canAccessTenant($a));
        $this->assertTrue($studio->canAccessTenant($b));
    }

    public function test_shop_owner_is_confined_to_their_own_shops(): void
    {
        $panel = Filament::getPanel('admin');

        $owner = User::factory()->create(['is_studio' => false]);
        $a = Shop::factory()->create();
        $b = Shop::factory()->create();
        $owner->shops()->attach($a);

        $tenants = $owner->getTenants($panel);
        $this->assertTrue($tenants->contains($a));
        $this->assertFalse($tenants->contains($b)); // never sees B

        $this->assertTrue($owner->canAccessTenant($a));
        $this->assertFalse($owner->canAccessTenant($b)); // and can't operate B
    }

    public function test_only_studio_users_can_access_the_shops_screen(): void
    {
        $studio = User::factory()->create(['is_studio' => true]);
        $owner = User::factory()->create(['is_studio' => false]);

        $this->actingAs($studio);
        $this->assertTrue(ShopResource::canAccess());

        $this->actingAs($owner);
        $this->assertFalse(ShopResource::canAccess());
    }

    public function test_registering_a_shop_persists_it(): void
    {
        // Shop is the tenant root (not tenant-owned), so it is created globally.
        Shop::create(['slug' => 'mandalay-musk', 'name' => 'Mandalay Musk', 'is_active' => true]);

        $this->assertDatabaseHas('shops', ['slug' => 'mandalay-musk', 'is_active' => true]);
    }
}

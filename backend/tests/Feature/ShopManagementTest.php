<?php

namespace Tests\Feature;

use App\Filament\Studio\Resources\Shops\Pages\ManageShops;
use App\Filament\Studio\Resources\Shops\ShopResource;
use App\Models\DeliveryTownship;
use App\Models\DeliveryTownshipCourier;
use App\Models\Shop;
use App\Models\User;
use App\Support\NationalGeography;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
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

    public function test_the_studio_panel_admits_studio_users_only(): void
    {
        // The studio panel (/studio) is the super-admin home outside any shop.
        // Gate is User::canAccessPanel: guests bounce to its login, a would-be
        // shop owner is 403'd, the studio operator gets the shop registry.
        $this->get('/studio/shops')
            ->assertRedirect('/studio/login');

        $this->actingAs(User::factory()->create(['is_studio' => false]));
        $this->get('/studio/shops')->assertForbidden();

        $this->actingAs(User::factory()->create(['is_studio' => true]));
        $this->get('/studio/shops')->assertOk();
        $this->get('/studio')->assertRedirect(); // home → the shop registry
    }

    public function test_registering_a_shop_through_the_panel_seeds_its_delivery_geography(): void
    {
        // The REAL registration flow — ManageShops' CreateAction, not Shop::create —
        // because onboarding side effects hang off the action: a shop with zero
        // townships has nothing offerable at checkout (design-doc §7 / prompts/25).
        $this->actingAs(User::factory()->create(['is_studio' => true]));

        Livewire::test(ManageShops::class)
            ->callAction('create', data: [
                'name' => 'Mandalay Musk',
                'slug' => 'mandalay-musk',
                'status' => 'live',
                'create_owner' => false, // the studio operates this one itself
            ])
            ->assertHasNoActionErrors();

        $shop = Shop::where('slug', 'mandalay-musk')->firstOrFail();
        $this->assertSame(1, User::count()); // no owner login was invented
        $context = app(TenantContext::class);

        // The default shop's zone table is untouched by another shop's onboarding.
        $this->assertSame(0, DeliveryTownship::count());

        // The new shop got the whole national chart — copied, inactive, unpriced:
        // the seed never invents a fee; activating and pricing is go-live work.
        $context->set($shop);
        $this->assertGreaterThan(200, DeliveryTownship::count());
        $this->assertSame(0, DeliveryTownship::where('is_active', true)->count());
        $this->assertSame(0, (int) DeliveryTownship::max('fee_mmk'));
        $this->assertGreaterThan(0, DeliveryTownshipCourier::count()); // routes came too
    }

    public function test_registering_a_shop_creates_its_owner_login(): void
    {
        // Registration's second fact: the owner's account. A SHOP-level admin —
        // full control of their shop through membership — never is_studio, which
        // would hand them every shop on the platform.
        $this->actingAs(User::factory()->create(['is_studio' => true]));

        Livewire::test(ManageShops::class)
            ->callAction('create', data: [
                'name' => 'Mandalay Musk',
                'slug' => 'mandalay-musk',
                'status' => 'live',
                'create_owner' => true,
                'owner_name' => 'Ma Thida',
                'owner_email' => 'owner@mandalaymusk.local',
                'owner_password' => 'a-strong-password',
            ])
            ->assertHasNoActionErrors();

        $shop = Shop::where('slug', 'mandalay-musk')->firstOrFail();
        $owner = User::where('email', 'owner@mandalaymusk.local')->firstOrFail();

        $this->assertFalse($owner->is_studio);
        $this->assertTrue(Hash::check('a-strong-password', $owner->password));
        $this->assertTrue($owner->canAccessTenant($shop));
        $this->assertSame(1, $owner->shops()->count()); // exactly their shop
    }

    public function test_a_shop_owner_is_confined_to_their_own_panel_urls(): void
    {
        // The §8 case ADR-0003 owed "when the pivot lands": a client user of
        // shop A requesting shop B's panel URL gets the 404 — and the studio
        // panel is a 403, whoever's shop they own.
        $a = Shop::factory()->create(['slug' => 'shop-a']);
        $b = Shop::factory()->create(['slug' => 'shop-b']);

        $owner = User::factory()->create(['is_studio' => false]);
        $owner->shops()->attach($a);
        $this->actingAs($owner);

        $this->get('/admin/shop-a')->assertOk();        // their own shop's dashboard
        $this->get('/admin/shop-b')->assertNotFound();  // someone else's — generic 404
        $this->get('/studio/shops')->assertForbidden(); // never the studio
    }

    public function test_the_shop_panel_brand_is_the_current_shops_name(): void
    {
        // The topbar names the shop you're standing in, not the platform —
        // asserted on getBrandName() itself because the tenant switcher also
        // prints the shop name, which would make a page assertSee vacuous.
        $panel = Filament::getPanel('admin');
        $shop = Shop::factory()->create(['name' => 'Fragnant by Pop']);

        $this->assertSame('Decant Please!', $panel->getBrandName()); // no tenant (login)

        $this->actingAs(User::factory()->create(['is_studio' => true]));
        Filament::setTenant($shop);

        $this->assertSame('Fragnant by Pop', $panel->getBrandName());
    }

    public function test_shop_onboarding_geography_seed_is_idempotent(): void
    {
        $shop = Shop::factory()->create(['slug' => 'mandalay-musk']);
        NationalGeography::seed($shop);

        app(TenantContext::class)->set($shop);
        $first = DeliveryTownship::count();
        $bahan = DeliveryTownship::where('name', 'Bahan')->firstOrFail();
        $bahan->update(['is_active' => true, 'fee_mmk' => 2500]); // the decanter went live

        NationalGeography::seed($shop); // a retried action or re-run runbook step

        $this->assertSame($first, DeliveryTownship::count()); // no duplicates
        $bahan->refresh();
        $this->assertTrue($bahan->is_active);      // never resets an activation
        $this->assertSame(2500, $bahan->fee_mmk);  // never clobbers a price
    }
}

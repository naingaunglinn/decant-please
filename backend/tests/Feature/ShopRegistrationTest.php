<?php

namespace Tests\Feature;

use App\Enums\ShopStatus;
use App\Filament\Studio\Resources\Shops\Pages\ManageShops;
use App\Models\DeliveryTownship;
use App\Models\Shop;
use App\Models\ShopDomain;
use App\Models\ShopSetting;
use App\Models\User;
use App\Support\ShopRegistration;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Step 44a: registering a shop is one domain method — the Studio and the self-serve
 * sign-up both call it — and publishing is the seller's own onboarding → live,
 * never a way back from suspension. shops, users and shop_domains are platform
 * tables; nothing here spends the withoutTenancy() budget.
 */
class ShopRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $slug = 'mandalay-musk', ?array $owner = null): Shop
    {
        return ShopRegistration::register(name: 'Mandalay Musk', slug: $slug, owner: $owner);
    }

    public function test_registering_creates_the_shop_category_owner_address_and_geography(): void
    {
        config(['app.storefront_base_domain' => 'cornerarea.me']);

        $shop = $this->register(owner: [
            'name' => 'Ma Thida',
            'email' => 'owner@mandalaymusk.local',
            'password' => 'a-strong-password',
        ]);

        $this->assertSame(ShopStatus::Onboarding, $shop->status); // self-serve default

        $owner = User::where('email', 'owner@mandalaymusk.local')->firstOrFail();
        $this->assertFalse($owner->isStudioAdmin());
        $this->assertTrue($owner->hasRole('shop_owner'));
        $this->assertSame([$shop->id], $owner->shops()->pluck('shops.id')->all());

        $domain = $shop->domains()->sole();
        $this->assertSame('mandalay-musk.cornerarea.me', $domain->host);
        $this->assertTrue($domain->is_primary);
        $this->assertNotNull($domain->verified_at); // the wildcard already reaches it

        app(TenantContext::class)->set($shop);
        $this->assertSame('decant', ShopSetting::current()->template);
        $this->assertGreaterThan(200, DeliveryTownship::count());
        $this->assertSame(0, DeliveryTownship::where('is_active', true)->count());
    }

    public function test_no_automatic_address_while_the_base_domain_is_blank(): void
    {
        config(['app.storefront_base_domain' => null]);

        $shop = $this->register();

        $this->assertSame(0, $shop->domains()->count());
    }

    public function test_the_studio_register_action_gives_the_shop_its_platform_address(): void
    {
        config(['app.storefront_base_domain' => 'cornerarea.me']);
        $this->actingAs(User::factory()->studio()->create());

        Livewire::test(ManageShops::class)
            ->callAction('create', data: [
                'name' => 'Mandalay Musk',
                'slug' => 'mandalay-musk',
                'status' => 'live',
                'create_owner' => false,
            ])
            ->assertHasNoActionErrors();

        $shop = Shop::where('slug', 'mandalay-musk')->firstOrFail();
        $this->assertSame(ShopStatus::Live, $shop->status); // the Studio may pick live
        $this->assertSame('mandalay-musk.cornerarea.me', $shop->domains()->sole()->host);

        // live + verified → the shared storefront resolves it at once (F6)
        $this->getJson('/api/v1/_storefront/host/mandalay-musk.cornerarea.me')
            ->assertOk()
            ->assertJsonPath('data.slug', 'mandalay-musk');
    }

    public function test_the_studio_form_refuses_a_reserved_slug(): void
    {
        $this->actingAs(User::factory()->studio()->create());

        Livewire::test(ManageShops::class)
            ->callAction('create', data: [
                'name' => 'Not the API',
                'slug' => 'api',
                'status' => 'live',
                'create_owner' => false,
            ])
            ->assertHasActionErrors(['slug']);

        $this->assertFalse(Shop::where('slug', 'api')->exists());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badSlugs(): array
    {
        return [
            'platform api host' => ['api'],
            'image host' => ['images'],
            'www' => ['www'],
            'studio' => ['studio'],
            'uppercase' => ['Mandalay'],
            'underscore' => ['mandalay_musk'],
            'leading dash' => ['-mandalay'],
            'trailing dash' => ['mandalay-'],
            'punycode look-alike' => ['xn--mndalay-9za'],
            'dotted' => ['a.b'],
            'too short' => ['ab'],
            'too long' => [str_repeat('a', 41)],
        ];
    }

    #[DataProvider('badSlugs')]
    public function test_register_itself_refuses_a_slug_that_cant_be_a_shop_address(string $slug): void
    {
        config(['app.storefront_base_domain' => 'cornerarea.me']);
        $shops = Shop::count();

        try {
            $this->register($slug);
            $this->fail("'{$slug}' was accepted.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }

        // refused before anything was written
        $this->assertSame($shops, Shop::count());
        $this->assertSame(0, ShopDomain::count());
    }

    public function test_a_taken_slug_or_platform_host_fails_cleanly(): void
    {
        config(['app.storefront_base_domain' => 'cornerarea.me']);
        $this->register('mandalay-musk');

        try {
            $this->register('mandalay-musk');
            $this->fail('A duplicate slug was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }

        // A custom-domain row the Studio added by hand already owns the host the
        // new slug would get: refused, and no half-registered shop is left behind.
        Shop::factory()->create(['slug' => 'yangon-scents'])
            ->domains()->create(['host' => 'bago-bakes.cornerarea.me', 'is_primary' => true]);

        try {
            $this->register('bago-bakes');
            $this->fail('A host already mapped to another shop was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }

        $this->assertFalse(Shop::where('slug', 'bago-bakes')->exists());
    }

    public function test_the_studio_form_shows_a_taken_address_on_the_slug_field(): void
    {
        config(['app.storefront_base_domain' => 'cornerarea.me']);
        Shop::factory()->create(['slug' => 'yangon-scents'])
            ->domains()->create(['host' => 'bago-bakes.cornerarea.me', 'is_primary' => true]);
        $this->actingAs(User::factory()->studio()->create());

        Livewire::test(ManageShops::class)
            ->callAction('create', data: [
                'name' => 'Bago Bakes',
                'slug' => 'bago-bakes',
                'status' => 'live',
                'create_owner' => false,
            ])
            ->assertHasActionErrors(['slug']);

        $this->assertFalse(Shop::where('slug', 'bago-bakes')->exists());
    }

    public function test_a_taken_owner_email_is_refused_before_anything_is_written(): void
    {
        config(['app.storefront_base_domain' => 'cornerarea.me']);
        User::factory()->create(['email' => 'owner@mandalaymusk.local']);

        try {
            $this->register(owner: [
                'name' => 'Ma Thida',
                'email' => 'owner@mandalaymusk.local',
                'password' => 'a-strong-password',
            ]);
            $this->fail('A taken email was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors()); // not a 500
        }

        $this->assertFalse(Shop::where('slug', 'mandalay-musk')->exists());
        $this->assertSame(0, ShopDomain::count());
    }

    public function test_a_failure_inside_registration_leaves_no_half_registered_shop(): void
    {
        // Shop, category, owner and address commit together: the unknown category
        // throws after the shop row is inserted, and the whole transaction goes.
        config(['app.storefront_base_domain' => 'cornerarea.me']);
        $context = app(TenantContext::class);
        $before = $context->get();

        try {
            ShopRegistration::register(name: 'Mandalay Musk', slug: 'mandalay-musk', template: 'no-such-category', owner: [
                'name' => 'Ma Thida',
                'email' => 'owner@mandalaymusk.local',
                'password' => 'a-strong-password',
            ]);
            $this->fail('An unknown category was accepted.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e);
        }

        $this->assertFalse(Shop::where('slug', 'mandalay-musk')->exists());
        $this->assertFalse(User::where('email', 'owner@mandalaymusk.local')->exists());
        $this->assertSame(0, ShopDomain::count());
        $this->assertSame($before, $context->get()); // the caller's tenant is untouched
    }

    public function test_registering_leaves_the_callers_tenant_context_as_it_was(): void
    {
        $context = app(TenantContext::class);
        $before = $context->get();

        $this->register();

        $this->assertSame($before, $context->get());
    }

    public function test_an_onboarding_shop_is_not_served_until_its_owner_publishes_it(): void
    {
        config(['app.storefront_base_domain' => 'cornerarea.me']);
        $shop = $this->register(owner: [
            'name' => 'Ma Thida',
            'email' => 'owner@mandalaymusk.local',
            'password' => 'a-strong-password',
            'phone' => '+959791234567', // verified by the sign-up (step 44b)
        ]);
        $owner = User::where('email', 'owner@mandalaymusk.local')->firstOrFail();
        $this->assertNotNull($owner->phone_verified_at);

        $this->getJson('/api/v1/_storefront/host/mandalay-musk.cornerarea.me')->assertNotFound();
        $this->getJson('/api/v1/mandalay-musk/meta')->assertNotFound();

        // the CORS allowlist is cached (60s) with only live shops in it
        $this->assertNotContains('https://mandalay-musk.cornerarea.me', ShopDomain::corsOrigins());

        $shop->publish($owner);

        $this->assertSame(ShopStatus::Live, $shop->status);
        $this->getJson('/api/v1/_storefront/host/mandalay-musk.cornerarea.me')
            ->assertOk()
            ->assertJsonPath('data.slug', 'mandalay-musk');
        $this->getJson('/api/v1/mandalay-musk/meta')->assertOk();
        // publish busts the cached allowlist, so the shop's own address is let in now
        $this->assertContains('https://mandalay-musk.cornerarea.me', ShopDomain::corsOrigins());
    }

    public function test_a_stale_model_cannot_publish_a_shop_the_studio_has_since_suspended(): void
    {
        $shop = $this->register();
        $owner = User::factory()->phoneVerified()->create();
        $owner->shops()->attach($shop);

        $stale = Shop::findOrFail($shop->id); // loaded while still onboarding
        Shop::findOrFail($shop->id)->suspend(User::factory()->studio()->create(), 'Unpaid invoice');

        try {
            $stale->publish($owner);
            $this->fail('A stale onboarding model published a suspended shop.');
        } catch (\DomainException) {
            // refused
        }

        $this->assertSame(ShopStatus::Suspended, $shop->fresh()->status);
    }

    public function test_another_shops_owner_cannot_publish_it(): void
    {
        $shop = $this->register();
        $stranger = User::factory()->phoneVerified()->create();
        $stranger->shops()->attach(Shop::factory()->create());

        $this->expectException(AuthorizationException::class);

        try {
            $shop->publish($stranger);
        } finally {
            $this->assertSame(ShopStatus::Onboarding, $shop->fresh()->status);
        }
    }

    public function test_a_member_without_a_verified_phone_cannot_publish(): void
    {
        $shop = $this->register();
        $owner = User::factory()->create(); // a Studio-registered owner: no phone
        $owner->shops()->attach($shop);

        $this->expectException(AuthorizationException::class);

        try {
            $shop->publish($owner);
        } finally {
            $this->assertSame(ShopStatus::Onboarding, $shop->fresh()->status);
        }
    }

    public function test_a_studio_admin_may_publish_any_onboarding_shop(): void
    {
        $shop = $this->register();

        $shop->publish(User::factory()->studio()->create());

        $this->assertSame(ShopStatus::Live, $shop->fresh()->status);
    }

    /**
     * @return array<string, array{ShopStatus}>
     */
    public static function unpublishableStatuses(): array
    {
        return [
            'suspended — never a self-unsuspend' => [ShopStatus::Suspended],
            'archived' => [ShopStatus::Archived],
            'already live' => [ShopStatus::Live],
        ];
    }

    #[DataProvider('unpublishableStatuses')]
    public function test_only_an_onboarding_shop_can_be_published(ShopStatus $status): void
    {
        $shop = Shop::factory()->status($status)->create();
        $owner = User::factory()->phoneVerified()->create();
        $owner->shops()->attach($shop);

        $this->expectException(\DomainException::class);

        try {
            $shop->publish($owner);
        } finally {
            $this->assertSame($status, $shop->fresh()->status);
        }
    }
}

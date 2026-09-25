<?php

namespace Tests\Feature;

use App\Enums\ShopStatus;
use App\Filament\Auth\SignUp;
use App\Filament\Pages\Dashboard;
use App\Models\DeliveryTownship;
use App\Models\Shop;
use App\Models\ShopSetting;
use App\Models\User;
use App\Support\PhoneVerification;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Step 44b: a seller signs up by themselves — account, verified phone and shop on
 * one admin page, through ShopRegistration::register(). Sign-up fails closed: no
 * working code sender, no sign-up. Codes are platform-level (no shop yet).
 */
class SelfServeSignupTest extends TestCase
{
    use RefreshDatabase;

    private ?string $code = null;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        // the log driver's line carries the code — read it the way a developer would
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->code = $e->context['code'] ?? $this->code;
        });
    }

    private function signUpOn(): void
    {
        config(['services.phone_verification.driver' => 'log']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function form(array $overrides = []): Testable
    {
        return Livewire::test(SignUp::class)->fillForm([
            'name' => 'Ma Thida',
            'phone' => '09 791 234 567',
            'email' => 'thida@example.test',
            'password' => 'a-strong-password',
            'passwordConfirmation' => 'a-strong-password',
            'shop_name' => 'Thida Closet',
            'slug' => 'thida-closet',
            'template' => 'clothing',
            ...$overrides,
        ]);
    }

    public function test_a_seller_signs_up_with_a_verified_phone_and_gets_one_onboarding_shop(): void
    {
        $this->signUpOn();
        config(['app.storefront_base_domain' => 'cornerarea.me']);

        $this->get('/admin/register')->assertOk()->assertSee('Open your shop');

        $page = $this->form()->call('sendCode')->assertHasNoFormErrors();
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $this->code);

        $page->fillForm(['code' => $this->code])
            ->call('register')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $shop = Shop::where('slug', 'thida-closet')->firstOrFail();
        $user = User::where('email', 'thida@example.test')->firstOrFail();

        $this->assertSame(ShopStatus::Onboarding, $shop->status);
        app(TenantContext::class)->set($shop);
        $this->assertSame('clothing', ShopSetting::query()->value('template'));
        // the geography seeds after register()'s own commit (the page adds no outer transaction)
        $this->assertGreaterThan(0, DeliveryTownship::query()->count());
        $this->assertSame(0, DeliveryTownship::query()->where('is_active', true)->count());
        $this->assertSame('thida-closet.cornerarea.me', $shop->domains()->value('host'));
        $this->assertSame('+959791234567', $user->phone);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame([$shop->id], $user->shops()->pluck('shops.id')->all());
        $this->assertTrue($user->hasRole('shop_owner'));
        $this->assertFalse($user->isStudioAdmin());
        $this->assertTrue(Auth::check());

        // the field hashed the password once; the model's cast must not hash it again
        Auth::logout();
        $this->assertTrue(Auth::attempt(['email' => 'thida@example.test', 'password' => 'a-strong-password']));

        // a code works once
        $this->expectException(ValidationException::class);
        PhoneVerification::check('+959791234567', (string) $this->code);
    }

    public function test_the_new_owner_is_confined_to_their_own_shop(): void
    {
        $this->signUpOn();
        $other = Shop::factory()->create(['slug' => 'someone-else']);

        $this->form()->call('sendCode')->fillForm(['code' => $this->code])->call('register')->assertHasNoFormErrors();

        $this->get('/admin/thida-closet')->assertOk()->assertSee('Open my shop');
        $this->get("/admin/{$other->slug}")->assertNotFound();
        $this->get('/studio/shops')->assertForbidden();
    }

    public function test_sign_up_is_off_when_no_sender_is_configured(): void
    {
        $this->get('/admin/register')->assertNotFound();
        $this->get('/admin/login')->assertOk()->assertDontSee('/admin/register');

        $this->assertFalse(PhoneVerification::enabled());
        Livewire::test(SignUp::class)->assertStatus(404);
    }

    public function test_the_log_driver_never_turns_sign_up_on_in_production(): void
    {
        $this->signUpOn();
        $this->app['env'] = 'production';

        try {
            $this->assertFalse(PhoneVerification::enabled());
            $this->get('/admin/register')->assertNotFound();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_a_wrong_code_is_refused_and_nothing_is_written(): void
    {
        $this->signUpOn();

        $this->form()->call('sendCode')
            ->fillForm(['code' => $this->code === '000000' ? '111111' : '000000'])
            ->call('register')
            ->assertHasFormErrors(['code']);

        $this->assertFalse(Shop::where('slug', 'thida-closet')->exists());
        $this->assertFalse(User::where('email', 'thida@example.test')->exists());
        $this->assertFalse(Auth::check());
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->signUpOn();
        $page = $this->form()->call('sendCode');

        $this->travel(PhoneVerification::CODE_TTL_SECONDS + 1)->seconds();

        $page->fillForm(['code' => $this->code])->call('register')->assertHasFormErrors(['code']);
        $this->assertFalse(Shop::where('slug', 'thida-closet')->exists());
    }

    public function test_the_fifth_wrong_code_drops_the_code(): void
    {
        $this->signUpOn();
        PhoneVerification::send('+959791234567', '10.0.0.1');
        $wrong = $this->code === '000000' ? '111111' : '000000';

        for ($i = 1; $i <= PhoneVerification::MAX_ATTEMPTS; $i++) {
            try {
                PhoneVerification::check('+959791234567', $wrong);
            } catch (ValidationException $e) {
                $last = $e->errors()['code'][0];
            }
        }

        $this->assertStringContainsString('Too many wrong codes', $last);

        // even the right code is no good now — ask for a new one
        $this->expectException(ValidationException::class);
        PhoneVerification::check('+959791234567', (string) $this->code);
    }

    public function test_sends_are_throttled_per_phone_and_per_ip(): void
    {
        $this->signUpOn();

        foreach (range(1, 3) as $_) {
            PhoneVerification::send('+959791234567', '10.0.0.1');
        }

        try {
            PhoneVerification::send('+959791234567', '10.0.0.2');
            $this->fail('A fourth code went to the same phone.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Too many codes', $e->errors()['phone'][0]);
        }

        // one IP asking for many phones
        foreach (range(1, 7) as $n) {
            PhoneVerification::send('+95979000000'.$n, '10.0.0.1');
        }

        try {
            PhoneVerification::send('+959790000009', '10.0.0.1');
            $this->fail('An eleventh code went out from one IP.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Too many codes', $e->errors()['phone'][0]);
        }
    }

    public function test_the_right_code_still_works_after_four_wrong_ones(): void
    {
        $this->signUpOn();
        PhoneVerification::send('+959791234567', '10.0.0.1');
        $wrong = $this->code === '000000' ? '111111' : '000000';

        for ($i = 1; $i < PhoneVerification::MAX_ATTEMPTS; $i++) {
            try {
                PhoneVerification::check('+959791234567', $wrong);
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Wrong code', $e->errors()['code'][0]);
            }
        }

        PhoneVerification::check('+959791234567', (string) $this->code);
        $this->addToAssertionCount(1); // no exception
    }

    public function test_a_phone_that_already_has_an_account_gets_no_code(): void
    {
        $this->signUpOn();
        User::factory()->create(['phone' => '+959791234567', 'phone_verified_at' => now()]);

        $this->form()->call('sendCode')->assertHasFormErrors(['phone']);
        $this->assertNull($this->code);
    }

    public function test_a_reserved_slug_shows_on_the_field_and_keeps_the_code(): void
    {
        $this->signUpOn();

        $page = $this->form(['slug' => 'studio'])->call('sendCode');
        $page->fillForm(['code' => $this->code])->call('register')->assertHasFormErrors(['slug']);

        $this->assertFalse(User::where('email', 'thida@example.test')->exists());

        // the seller fixes the address — the same code still works
        $page->fillForm(['slug' => 'thida-closet'])->call('register')->assertHasNoFormErrors();
        $this->assertTrue(Shop::where('slug', 'thida-closet')->exists());
    }

    /** @return array<string, array{string, ?string}> */
    public static function phones(): array
    {
        return [
            '09 with spaces' => ['09 791 234 567', '+959791234567'],
            '+95 9' => ['+95 9 791234567', '+959791234567'],
            '959' => ['959791234567', '+959791234567'],
            'dashes' => ['09-4500-1234', '+95945001234'],
            '+95 09' => ['+95 09 791 234 567', '+959791234567'],
            'landline' => ['01 234 567', null],
            'foreign' => ['+66 81 234 5678', null],
            'too short' => ['09 12', null],
        ];
    }

    #[DataProvider('phones')]
    public function test_phones_normalize_to_one_spelling(string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneVerification::normalize($input));
    }

    // ---- the Publish button (dashboard) ------------------------------------

    private function onboardingShopOwnedBy(User $owner): Shop
    {
        $shop = Shop::factory()->status(ShopStatus::Onboarding)->create(['slug' => 'thida-closet']);
        $owner->shops()->attach($shop);
        $owner->assignRole('shop_owner');

        $this->actingAs($owner);
        // no TenantContext::set here: setTenant() must hand the shop over itself
        // (SyncTenantContextFromFilament) — the checklist queries depend on it
        Filament::setTenant($shop);

        return $shop;
    }

    public function test_a_verified_owner_publishes_from_the_dashboard(): void
    {
        // the default shop (the suite's preset context) has a product and an
        // active township; the new shop has neither — the checklist must say so
        $this->itemFragrance();
        $this->serviceableTownship();
        $shop = $this->onboardingShopOwnedBy(User::factory()->phoneVerified()->create());
        $this->assertSame($shop->id, app(TenantContext::class)->id());

        Livewire::test(Dashboard::class)
            ->assertActionVisible('publish')
            ->mountAction('publish')
            ->assertMountedActionModalSee(['No products yet', 'No delivery township switched on'])
            ->callMountedAction()
            ->assertNotified();

        $this->assertSame(ShopStatus::Live, $shop->fresh()->status);

        Livewire::test(Dashboard::class)->assertActionHidden('publish');
    }

    public function test_an_owner_without_a_verified_phone_sees_no_publish_button(): void
    {
        $shop = $this->onboardingShopOwnedBy(User::factory()->create());

        Livewire::test(Dashboard::class)->assertActionHidden('publish');
        $this->assertSame(ShopStatus::Onboarding, $shop->fresh()->status);
    }

    public function test_a_studio_admin_sees_no_publish_button(): void
    {
        $shop = Shop::factory()->status(ShopStatus::Onboarding)->create();
        $this->actingAs($this->studioUser());
        app(TenantContext::class)->set($shop);
        Filament::setTenant($shop);

        Livewire::test(Dashboard::class)->assertActionHidden('publish');
    }
}

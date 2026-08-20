<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Filament\Pages\ManagePayment;
use App\Models\Brand;
use App\Models\DecantPrice;
use App\Models\Order;
use App\Models\ShopSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    // ---- Checkout: method choice --------------------------------------------

    public function test_checkout_defaults_to_cod_when_no_method_is_sent(): void
    {
        $price = $this->inStockPrice();

        $this->checkout($price)->assertCreated();

        $this->assertSame(PaymentMethod::Cod, Order::firstOrFail()->payment_method);
    }

    public function test_online_checkout_stores_the_method_and_attaches_the_slip(): void
    {
        Storage::fake('local');
        $price = $this->inStockPrice();

        $this->checkout($price, method: 'online', proof: UploadedFile::fake()->image('slip.jpg'))
            ->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame(PaymentMethod::Online, $order->payment_method);
        $this->assertNotNull($order->payment_proof_path); // slip rode in with checkout
        Storage::disk('local')->assertExists($order->payment_proof_path);
    }

    public function test_online_checkout_requires_a_slip(): void
    {
        $price = $this->inStockPrice();

        $this->checkout($price, method: 'online') // no proof
            ->assertJsonValidationErrors('proof');
        $this->assertSame(0, Order::count());
    }

    public function test_cod_checkout_needs_no_slip(): void
    {
        $price = $this->inStockPrice();

        $this->checkout($price, method: 'cod')->assertCreated();
        $this->assertNull(Order::firstOrFail()->payment_proof_path);
    }

    public function test_checkout_rejects_an_unknown_method(): void
    {
        $price = $this->inStockPrice();

        $this->checkout($price, method: 'crypto')->assertJsonValidationErrors('payment_method');
        $this->assertSame(0, Order::count());
    }

    public function test_receipt_reports_the_payment_method(): void
    {
        Storage::fake('local');
        $price = $this->inStockPrice();
        $this->checkout($price, method: 'online', proof: UploadedFile::fake()->image('slip.jpg'));
        $order = Order::firstOrFail();

        $this->getJson('/api/v1/decant-please/orders/track?'.http_build_query([
            'tracking_code' => $order->tracking_code,
            'phone' => $order->phone,
        ]))
            ->assertOk()
            ->assertJsonPath('payment_method', 'online')
            ->assertJsonPath('payment_method_label', 'Online transfer');
    }

    // ---- Stock check (surfaced at Accept) -----------------------------------

    public function test_stock_shortfalls_flags_tracked_fragrances_that_cannot_be_filled(): void
    {
        $price = $this->inStockPrice();
        $price->fragrance->update(['stock_ml' => 8]); // only 8ml left; order needs 10ml

        $order = Order::create([
            'customer_name' => 'Aung Kyaw', 'phone' => '09-1', 'address' => 'Yangon',
            'order_from' => 'website', 'status' => 'awaiting_confirmation',
        ]);
        $order->items()->create([
            'fragrance_id' => $price->fragrance_id,
            'fragrance_name_snapshot' => 'Chanel Allure Homme Sport',
            'size_ml' => 10, 'unit_price_mmk' => 55000, 'quantity' => 1,
        ]);

        $short = $order->stockShortfalls();
        $this->assertCount(1, $short);
        $this->assertSame(10, $short[0]['needed']);
        $this->assertSame(8, $short[0]['available']);

        // an untracked fragrance (null stock) never counts as short
        $price->fragrance->update(['stock_ml' => null]);
        $this->assertSame([], $order->fresh()->stockShortfalls());
    }

    // ---- MMQR / shop payment settings ---------------------------------------

    public function test_shop_setting_has_one_row_per_shop(): void
    {
        // current() is an idempotent per-shop accessor: repeated calls return the
        // same row for the current tenant. count() is 1 here because this test runs
        // single-shop — the row is per-shop (BelongsToShop + unique(shop_id)), not a
        // global singleton.
        $a = ShopSetting::current();
        $b = ShopSetting::current();

        $this->assertTrue($a->is($b));
        $this->assertSame(1, ShopSetting::count());
    }

    public function test_meta_serves_admin_payment_settings_over_env(): void
    {
        config()->set('app.payment.kbzpay_number', '09-ENV-FALLBACK');
        ShopSetting::current()->update([
            'kbzpay_name' => 'Daw Mya',
            'kbzpay_number' => '09-777000111',
            'payment_instructions' => 'Note your order number.',
        ]);
        Cache::flush();

        $this->getJson('/api/v1/decant-please/meta')
            ->assertOk()
            ->assertJsonPath('payment.kbzpay_name', 'Daw Mya')
            ->assertJsonPath('payment.kbzpay_number', '09-777000111') // DB wins over env
            ->assertJsonPath('payment.instructions', 'Note your order number.');
    }

    public function test_saving_settings_busts_the_meta_cache(): void
    {
        Cache::flush();
        $this->getJson('/api/v1/decant-please/meta')->assertOk(); // primes the cache (payment = null)

        ShopSetting::current()->update(['kbzpay_number' => '09-555']);

        $this->getJson('/api/v1/decant-please/meta')->assertOk()->assertJsonPath('payment.kbzpay_number', '09-555');
    }

    // ---- Admin: the Payment settings page -----------------------------------

    public function test_admin_can_save_payment_settings_from_the_page(): void
    {
        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'admin@decantplease.local',
            'password' => 'secret-password',
        ]));

        Livewire::test(ManagePayment::class)
            ->fillForm([
                'kbzpay_name' => 'Daw Mya',
                'kbzpay_number' => '09-777000111',
                'payment_instructions' => 'Scan and pay the total.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = ShopSetting::current();
        $this->assertSame('Daw Mya', $settings->kbzpay_name);
        $this->assertSame('09-777000111', $settings->kbzpay_number);
    }

    public function test_admin_can_save_telegram_and_social_settings_and_a_blank_token_preserves_the_stored_one(): void
    {
        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'admin@decantplease.local',
            'password' => 'secret-password',
        ]));

        // First save sets the token + chat + socials.
        Livewire::test(ManagePayment::class)
            ->fillForm([
                'bot_token' => '123:BOTTOKEN',
                'admin_chat_id' => '987654',
                'tiktok_url' => 'https://tiktok.com/@decant',
                'facebook_url' => 'https://facebook.com/decant',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = ShopSetting::current();
        $this->assertSame('123:BOTTOKEN', $settings->bot_token); // decrypted via cast
        $this->assertSame('987654', $settings->admin_chat_id);
        $this->assertSame('https://tiktok.com/@decant', $settings->tiktok_url);

        // Mount does not echo the secret back into the form.
        Livewire::test(ManagePayment::class)->assertFormSet(['bot_token' => null]);

        // Saving again with a BLANK bot token must not wipe the stored one
        // (the field dehydrates only when filled), while other fields update.
        Livewire::test(ManagePayment::class)
            ->fillForm(['bot_token' => '', 'admin_chat_id' => '111222'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = ShopSetting::current();
        $this->assertSame('123:BOTTOKEN', $reloaded->bot_token, 'blank token field must preserve the stored token');
        $this->assertSame('111222', $reloaded->admin_chat_id);
    }

    // ---- helpers ------------------------------------------------------------

    private function inStockPrice(): DecantPrice
    {
        $brand = Brand::create(['name' => 'Chanel', 'type' => 'designer', 'is_active' => true]);
        $fragrance = $brand->fragrances()->create([
            'name' => 'Allure Homme Sport',
            'concentration' => 'cologne',
            'gender' => 'male',
            'is_active' => true,
        ]);

        return $fragrance->decantPrices()->create(['size_ml' => 10, 'price_mmk' => 55000, 'in_stock' => true]);
    }

    private function checkout(DecantPrice $price, ?string $method = null, ?UploadedFile $proof = null)
    {
        $payload = array_filter([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'delivery_township_id' => $this->serviceableTownship()->id,
            'address_line' => 'Sanchaung, Yangon',
            'payment_method' => $method,
            'items' => [[
                'fragrance_id' => $price->fragrance_id,
                'size_ml' => $price->size_ml,
                'quantity' => 1,
            ]],
        ], fn ($v) => $v !== null);

        // A slip means multipart; otherwise plain JSON (COD path).
        return $proof
            ? $this->post('/api/v1/decant-please/orders', $payload + ['proof' => $proof], ['Accept' => 'application/json'])
            : $this->postJson('/api/v1/decant-please/orders', $payload);
    }
}

<?php

namespace Tests\Feature;

use App\Events\OrderPlaced;
use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopSetting;
use App\Support\ShopConfig;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step 33 — per-shop configuration. Telegram credentials, social links, and
 * payment details resolve shop row → platform env → off, through App\Support\
 * ShopConfig, and every Telegram alert routes to the ORDER's shop, not a global
 * bot. Two shops with different config prove nothing leaks between them.
 */
class PerShopConfigTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shopA;

    private Shop $shopB;

    protected function setUp(): void
    {
        parent::setUp();

        // Both shops start with no Telegram env — each test opts a platform
        // default in explicitly, so a developer's real .env can't leak in.
        config()->set('services.telegram.bot_token', null);
        config()->set('services.telegram.admin_chat_id', null);

        $this->shopA = Shop::factory()->create(['slug' => 'shop-a', 'name' => 'Shop A']);
        $this->shopB = Shop::factory()->create(['slug' => 'shop-b', 'name' => 'Shop B']);
    }

    private function forShop(Shop $shop): void
    {
        app(TenantContext::class)->set($shop);
    }

    /** Set the given shop's settings row (runs under that shop's context). */
    private function configureShop(Shop $shop, array $attributes): void
    {
        $previous = app(TenantContext::class)->get();
        $this->forShop($shop);
        ShopSetting::current()->update($attributes);
        app(TenantContext::class)->set($previous);
    }

    private function makeOrderFor(Shop $shop): Order
    {
        $previous = app(TenantContext::class)->get();
        $this->forShop($shop);

        $order = Order::create([
            'customer_name' => 'Buyer',
            'phone' => '09-771234561',
            'address' => 'Somewhere, Yangon',
            'order_from' => 'website',
            'status' => 'awaiting_confirmation',
            'payment_method' => 'cod',
            'total_mmk' => 55000,
        ])->refresh();

        app(TenantContext::class)->set($previous);

        return $order;
    }

    // ---- A. Shop-setting isolation -----------------------------------------

    public function test_config_resolves_each_shops_own_settings(): void
    {
        $this->configureShop($this->shopA, [
            'admin_chat_id' => 'CHAT_A', 'tiktok_url' => 'https://tiktok.com/@a', 'kbzpay_number' => '111',
        ]);
        $this->configureShop($this->shopB, [
            'admin_chat_id' => 'CHAT_B', 'tiktok_url' => 'https://tiktok.com/@b', 'kbzpay_number' => '222',
        ]);

        $this->forShop($this->shopA);
        $this->assertSame('CHAT_A', ShopConfig::get('telegram.admin_chat_id'));
        $this->assertSame('https://tiktok.com/@a', ShopConfig::get('social.tiktok'));
        $this->assertSame('111', ShopConfig::get('payment.kbzpay_number'));

        $this->forShop($this->shopB);
        $this->assertSame('CHAT_B', ShopConfig::get('telegram.admin_chat_id'));
        $this->assertSame('https://tiktok.com/@b', ShopConfig::get('social.tiktok'));
        $this->assertSame('222', ShopConfig::get('payment.kbzpay_number'));
    }

    public function test_reading_one_shop_never_returns_another_shops_settings(): void
    {
        $this->configureShop($this->shopA, ['admin_chat_id' => 'CHAT_A']);
        $this->configureShop($this->shopB, ['admin_chat_id' => 'CHAT_B']);

        // forShop resolves the explicit shop regardless of ambient context, and
        // restores the ambient context afterwards.
        $this->forShop($this->shopB);
        $this->assertSame('CHAT_A', ShopConfig::forShop($this->shopA, 'telegram.admin_chat_id'));
        $this->assertSame('CHAT_B', ShopConfig::get('telegram.admin_chat_id'), 'ambient context must be restored to B');
    }

    public function test_meta_endpoint_returns_each_shops_own_social_and_payment(): void
    {
        $this->configureShop($this->shopA, ['tiktok_url' => 'https://tiktok.com/@a', 'kbzpay_number' => '111']);
        $this->configureShop($this->shopB, ['tiktok_url' => 'https://tiktok.com/@b', 'kbzpay_number' => '222']);

        $a = $this->getJson('/api/v1/shop-a/meta')->assertOk()->json();
        $b = $this->getJson('/api/v1/shop-b/meta')->assertOk()->json();

        $this->assertSame('https://tiktok.com/@a', $a['social']['tiktok_url']);
        $this->assertSame('111', $a['payment']['kbzpay_number']);
        $this->assertSame('https://tiktok.com/@b', $b['social']['tiktok_url']);
        $this->assertSame('222', $b['payment']['kbzpay_number']);
    }

    public function test_meta_social_falls_back_to_the_platform_env(): void
    {
        config()->set('app.social.tiktok', 'https://tiktok.com/@platform');

        // shop-a has no tiktok row → platform default; shop-b overrides it
        $this->configureShop($this->shopB, ['tiktok_url' => 'https://tiktok.com/@b']);

        $this->assertSame(
            'https://tiktok.com/@platform',
            $this->getJson('/api/v1/shop-a/meta')->json('social.tiktok_url'),
        );
        $this->assertSame(
            'https://tiktok.com/@b',
            $this->getJson('/api/v1/shop-b/meta')->json('social.tiktok_url'),
        );
    }

    // ---- B. Telegram routes to the order's shop ----------------------------

    public function test_each_shops_order_alerts_its_own_bot_and_chat(): void
    {
        $this->configureShop($this->shopA, ['bot_token' => 'A:TOKEN', 'admin_chat_id' => 'CHAT_A']);
        $this->configureShop($this->shopB, ['bot_token' => 'B:TOKEN', 'admin_chat_id' => 'CHAT_B']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        OrderPlaced::dispatch($this->makeOrderFor($this->shopA));
        OrderPlaced::dispatch($this->makeOrderFor($this->shopB));

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'botA:TOKEN/sendMessage') && $r['chat_id'] === 'CHAT_A');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'botB:TOKEN/sendMessage') && $r['chat_id'] === 'CHAT_B');
    }

    public function test_the_alert_follows_the_orders_shop_not_the_ambient_context(): void
    {
        $this->configureShop($this->shopA, ['bot_token' => 'A:TOKEN', 'admin_chat_id' => 'CHAT_A']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $orderA = $this->makeOrderFor($this->shopA);
        $this->forShop($this->shopB); // deliberately the WRONG ambient shop

        OrderPlaced::dispatch($orderA);

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'botA:TOKEN/sendMessage') && $r['chat_id'] === 'CHAT_A');
    }

    // ---- C. Platform fallback + unconfigured no-op --------------------------

    public function test_a_shop_without_its_own_token_uses_the_platform_bot(): void
    {
        config()->set('services.telegram.bot_token', 'PLATFORM:TOKEN');
        // shop-a has only a chat id — its token falls back to the platform bot
        $this->configureShop($this->shopA, ['admin_chat_id' => 'CHAT_A']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        OrderPlaced::dispatch($this->makeOrderFor($this->shopA));

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'botPLATFORM:TOKEN/sendMessage') && $r['chat_id'] === 'CHAT_A');
    }

    public function test_a_completely_unconfigured_shop_sends_nothing(): void
    {
        Http::fake();

        // no shop row, no platform env
        OrderPlaced::dispatch($this->makeOrderFor($this->shopA));

        Http::assertNothingSent();
    }

    // ---- D. Tenant-aware admin URL -----------------------------------------

    public function test_the_alert_links_to_the_tenant_aware_admin_url(): void
    {
        $this->configureShop($this->shopA, ['bot_token' => 'A:TOKEN', 'admin_chat_id' => 'CHAT_A']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $order = $this->makeOrderFor($this->shopA);
        OrderPlaced::dispatch($order);

        Http::assertSent(fn ($r) => str_contains($r['text'], "/admin/shop-a/orders/{$order->id}/edit"));
    }

    // ---- E. telegram:test uses the shop's own config ------------------------

    public function test_telegram_test_uses_the_named_shops_own_configuration(): void
    {
        $this->configureShop($this->shopA, ['bot_token' => 'A:TOKEN', 'admin_chat_id' => 'CHAT_A']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->artisan('telegram:test', ['shop' => 'shop-a'])->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'botA:TOKEN/sendMessage') && $r['chat_id'] === 'CHAT_A');
    }
}

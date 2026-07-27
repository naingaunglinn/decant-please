<?php

namespace Tests\Feature;

use App\Events\OrderPlaced;
use App\Models\Brand;
use App\Models\DecantPrice;
use App\Support\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramAlertTest extends TestCase
{
    use RefreshDatabase;

    private function configureTelegram(): void
    {
        config()->set('services.telegram.bot_token', 'TEST:TOKEN');
        config()->set('services.telegram.admin_chat_id', '123456');
    }

    // ---- TelegramNotifier --------------------------------------------------

    public function test_notifier_no_ops_when_unconfigured(): void
    {
        Http::fake();
        $notifier = new TelegramNotifier;

        $this->assertFalse($notifier->isConfigured());
        $this->assertFalse($notifier->sendToAdmin('hi'));
        Http::assertNothingSent();
    }

    public function test_notifier_posts_to_the_bot_api_when_configured(): void
    {
        $this->configureTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->assertTrue((new TelegramNotifier)->sendToAdmin('hello အောင်'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org/botTEST:TOKEN/sendMessage')
            && $request['chat_id'] === '123456'
            && $request['text'] === 'hello အောင်');
    }

    public function test_notifier_returns_false_and_never_throws_on_api_failure(): void
    {
        $this->configureTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);

        $this->assertFalse((new TelegramNotifier)->sendToAdmin('hi'));
    }

    // ---- Checkout wiring ---------------------------------------------------

    public function test_website_checkout_dispatches_order_placed_but_honeypot_does_not(): void
    {
        Event::fake([OrderPlaced::class]);
        $price = $this->inStockPrice();

        $this->checkout($price)->assertCreated();
        Event::assertDispatched(OrderPlaced::class);

        $this->checkout($price, honeypot: true)->assertCreated();
        Event::assertDispatchedTimes(OrderPlaced::class, 1); // honeypot added nothing
    }

    public function test_website_checkout_sends_an_admin_alert_with_the_order_details(): void
    {
        $this->configureTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $price = $this->inStockPrice();

        $this->checkout($price, quantity: 2)->assertCreated();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && $request['chat_id'] === '123456'
            && str_contains($request['text'], 'New order')
            && str_contains($request['text'], '10ml × 2'));
    }

    public function test_honeypot_checkout_sends_no_alert(): void
    {
        $this->configureTelegram();
        Http::fake();
        $price = $this->inStockPrice();

        $this->checkout($price, honeypot: true)->assertCreated();

        Http::assertNothingSent();
    }

    public function test_checkout_succeeds_when_telegram_is_unconfigured(): void
    {
        Http::fake();
        $price = $this->inStockPrice();

        $this->checkout($price)->assertCreated();

        Http::assertNothingSent(); // listener short-circuits when unconfigured
    }

    public function test_a_failing_telegram_never_breaks_checkout(): void
    {
        $this->configureTelegram();
        Http::fake(['api.telegram.org/*' => Http::response('boom', 500)]);
        $price = $this->inStockPrice();

        // the customer's order still goes through
        $this->checkout($price)->assertCreated();
    }

    // ---- telegram:test command ---------------------------------------------

    public function test_command_reports_success_when_configured(): void
    {
        $this->configureTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->artisan('telegram:test')->assertSuccessful();
    }

    public function test_command_fails_clearly_when_unconfigured(): void
    {
        $this->artisan('telegram:test')
            ->expectsOutputToContain('not configured')
            ->assertFailed();
    }

    // ---- helpers -----------------------------------------------------------

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

    private function checkout(DecantPrice $price, int $quantity = 1, bool $honeypot = false)
    {
        return $this->postJson('/api/v1/orders', [
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'website' => $honeypot ? 'https://spam.example' : '',
            'items' => [[
                'fragrance_id' => $price->fragrance_id,
                'size_ml' => $price->size_ml,
                'quantity' => $quantity,
            ]],
        ]);
    }
}

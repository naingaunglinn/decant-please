<?php

namespace Tests\Feature;

use App\Events\OrderPlaced;
use App\Events\PaymentProofUploaded;
use App\Models\Brand;
use App\Models\DecantPrice;
use App\Models\Order;
use App\Support\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function test_one_checkout_sends_exactly_one_alert(): void
    {
        // The listener is wired once, explicitly, in AppServiceProvider; event
        // auto-discovery is off (bootstrap/app.php) so it can't register a second
        // copy. With both active, one checkout alerted the admin twice (#52).
        $this->configureTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $price = $this->inStockPrice();

        $this->checkout($price)->assertCreated();

        Http::assertSentCount(1);
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

    // ---- Payment method in the new-order alert ------------------------------

    public function test_cod_alert_names_the_payment_method(): void
    {
        $this->configureTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $price = $this->inStockPrice();

        $this->checkout($price)->assertCreated(); // no method sent → cod

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Payment: Cash on delivery')
            && ! str_contains($request['text'], 'slip'));
    }

    public function test_online_checkout_alerts_once_with_the_slip_attached(): void
    {
        // One send total: the checkout slip is reported inside the new-order
        // alert, and checkout never dispatches PaymentProofUploaded on top.
        $this->configureTelegram();
        $this->fakeProofsDisk();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $price = $this->inStockPrice();

        $this->checkout($price, method: 'online', proof: UploadedFile::fake()->image('slip.jpg'))
            ->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'New order')
            && str_contains($request['text'], 'Payment: Online transfer — slip attached'));
    }

    public function test_an_online_order_with_no_slip_reads_slip_awaited(): void
    {
        // Unreachable via checkout (validation requires the slip), so exercise
        // the listener straight off the event — it mustn't assume its dispatcher.
        $this->configureTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        OrderPlaced::dispatch($this->order(method: 'online'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Payment: Online transfer — slip awaited'));
    }

    // ---- Slip-upload alerts -------------------------------------------------

    public function test_slip_upload_sends_exactly_one_alert_with_the_order_details(): void
    {
        $this->configureTelegram();
        $this->fakeProofsDisk();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $order = $this->order(method: 'online', total: 110000, deposit: 10000);

        $this->uploadProof($order)->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], "Payment slip uploaded — order #{$order->id}")
            && str_contains($request['text'], 'Aung Kyaw · 09-771234561')
            && str_contains($request['text'], 'Total: 110,000 Ks · Balance due: 100,000 Ks')
            && str_contains($request['text'], "Track: {$order->tracking_code}")
            && str_contains($request['text'], "/admin/orders/{$order->id}/edit"));
    }

    public function test_a_replacement_slip_says_replaced_not_uploaded(): void
    {
        // The endpoint overwrites the earlier object — the decanter should read
        // "same order, newer slip", not an identical buzz per blurry-photo retry.
        $this->configureTelegram();
        $this->fakeProofsDisk();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $order = $this->order(method: 'online', proofPath: 'payment-proofs/first.jpg');

        $this->uploadProof($order)->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], "Payment slip replaced — order #{$order->id}")
            && ! str_contains($request['text'], 'slip uploaded'));
    }

    public function test_slip_upload_dispatches_the_event_but_checkout_never_does(): void
    {
        Event::fake([PaymentProofUploaded::class]);
        $this->fakeProofsDisk();
        $price = $this->inStockPrice();

        $this->checkout($price, method: 'online', proof: UploadedFile::fake()->image('slip.jpg'))
            ->assertCreated();
        Event::assertNotDispatched(PaymentProofUploaded::class);

        $order = Order::firstOrFail();
        $this->uploadProof($order)->assertOk();

        // …and the checkout slip already on file makes this upload a replacement.
        Event::assertDispatched(PaymentProofUploaded::class,
            fn (PaymentProofUploaded $event) => $event->order->is($order) && $event->isReplacement);
    }

    public function test_unconfigured_slip_upload_sends_nothing_and_still_succeeds(): void
    {
        Http::fake();
        $this->fakeProofsDisk();
        $order = $this->order(method: 'online');

        $this->uploadProof($order)->assertOk();

        Http::assertNothingSent(); // listener short-circuits when unconfigured
    }

    public function test_a_failing_telegram_never_breaks_a_slip_upload(): void
    {
        $this->configureTelegram();
        $this->fakeProofsDisk();
        Http::fake(['api.telegram.org/*' => Http::response('boom', 500)]);
        $order = $this->order(method: 'online');

        // the customer's upload still succeeds
        $this->uploadProof($order)->assertOk();
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

    /** Fake the private proofs disk ("local" under test) so no slip touches real storage. */
    private function fakeProofsDisk(): void
    {
        Storage::fake(config('filesystems.proofs_disk'));
    }

    /** An order created directly, bypassing the checkout endpoint (and its validation). */
    private function order(
        string $method = 'cod',
        int $total = 55000,
        int $deposit = 0,
        ?string $proofPath = null,
    ): Order {
        $price = $this->inStockPrice();

        $order = Order::create([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'order_from' => 'website',
            'status' => 'awaiting_confirmation',
            'payment_method' => $method,
            'total_mmk' => $total,
            'deposit_mmk' => $deposit,
            'payment_proof_path' => $proofPath,
        ])->refresh();

        $order->items()->create([
            'fragrance_id' => $price->fragrance_id,
            'fragrance_name_snapshot' => 'Chanel Allure Homme Sport',
            'size_ml' => $price->size_ml,
            'unit_price_mmk' => $price->price_mmk,
            'quantity' => 1,
        ]);

        return $order;
    }

    private function uploadProof(Order $order)
    {
        return $this->postJson('/api/v1/orders/payment-proof', [
            'tracking_code' => $order->tracking_code,
            'phone' => $order->phone,
            'proof' => UploadedFile::fake()->image('slip.jpg'),
        ]);
    }

    private function checkout(
        DecantPrice $price,
        int $quantity = 1,
        bool $honeypot = false,
        ?string $method = null,
        ?UploadedFile $proof = null,
    ) {
        $payload = [
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'delivery_township_id' => $this->serviceableTownship()->id,
            'address_line' => 'Sanchaung, Yangon',
            'website' => $honeypot ? 'https://spam.example' : '',
            'items' => [[
                'fragrance_id' => $price->fragrance_id,
                'size_ml' => $price->size_ml,
                'quantity' => $quantity,
            ]],
        ];

        if ($method !== null) {
            $payload['payment_method'] = $method;
        }

        // A slip means multipart; otherwise plain JSON — same split real clients make.
        return $proof
            ? $this->post('/api/v1/orders', $payload + ['proof' => $proof], ['Accept' => 'application/json'])
            : $this->postJson('/api/v1/orders', $payload);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Widgets\OrderStats;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    // ---- Model -------------------------------------------------------------

    public function test_mark_paid_and_unpaid_toggle_status_and_timestamp(): void
    {
        $order = $this->order();
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertNull($order->paid_at);

        $order->markPaid();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertNotNull($order->paid_at);

        $order->markUnpaid();
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertNull($order->paid_at);
    }

    public function test_setting_payment_status_directly_syncs_paid_at(): void
    {
        // The admin form's status select writes the column directly — paid_at must
        // still stay consistent, via the model saving hook (not just markPaid()).
        $order = $this->order();

        $order->update(['payment_status' => PaymentStatus::Paid]);
        $this->assertNotNull($order->fresh()->paid_at);

        $order->update(['payment_status' => PaymentStatus::Unpaid]);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_balance_due_subtracts_the_deposit(): void
    {
        $order = $this->order(total: 55000, deposit: 20000);
        $this->assertSame(35000, $order->balanceDue());

        // never negative, even if a deposit somehow exceeds the total
        $this->assertSame(0, $this->order(total: 10000, deposit: 15000)->balanceDue());
    }

    public function test_deleting_an_order_removes_its_proof_file(): void
    {
        $disk = $this->fakeProofsDisk();
        $order = $this->order();
        $path = UploadedFile::fake()->image('proof.jpg')->store('payment-proofs', $disk);
        $order->attachPaymentProof($path);
        Storage::disk($disk)->assertExists($path);

        $order->delete();
        Storage::disk($disk)->assertMissing($path);
    }

    public function test_replacing_the_proof_directly_deletes_the_old_object(): void
    {
        // The admin form writes payment_proof_path itself (no controller in the
        // loop) — the model's updated hook must clean up the replaced object.
        $disk = $this->fakeProofsDisk();
        $order = $this->order();

        $first = UploadedFile::fake()->image('first.jpg')->store('payment-proofs', $disk);
        $order->update(['payment_proof_path' => $first]);
        Storage::disk($disk)->assertExists($first); // first upload deletes nothing

        $second = UploadedFile::fake()->image('second.jpg')->store('payment-proofs', $disk);
        $order->update(['payment_proof_path' => $second]);

        Storage::disk($disk)->assertMissing($first);
        Storage::disk($disk)->assertExists($second);
    }

    // ---- Public API --------------------------------------------------------

    public function test_meta_exposes_configured_payment_details_and_hides_blanks(): void
    {
        config()->set('app.payment.kbzpay_name', 'Daw Mya');
        config()->set('app.payment.kbzpay_number', '09-777000111');
        config()->set('app.payment.wave_name', null);   // blank -> hidden
        config()->set('app.payment.instructions', 'Note your order number.');
        Cache::flush(); // /meta is cached

        $this->getJson('/api/v1/decant-please/meta')
            ->assertOk()
            ->assertJsonPath('payment.kbzpay_name', 'Daw Mya')
            ->assertJsonPath('payment.kbzpay_number', '09-777000111')
            ->assertJsonPath('payment.instructions', 'Note your order number.')
            ->assertJsonMissingPath('payment.wave_name');
    }

    public function test_meta_payment_is_null_when_nothing_configured(): void
    {
        foreach (['kbzpay_name', 'kbzpay_number', 'wave_name', 'wave_number', 'qr_url', 'instructions'] as $key) {
            config()->set("app.payment.{$key}", null);
        }
        Cache::flush();

        $this->getJson('/api/v1/decant-please/meta')->assertOk()->assertJsonPath('payment', null);
    }

    public function test_customer_uploads_payment_proof_with_matching_code_and_phone(): void
    {
        $disk = $this->fakeProofsDisk();
        Storage::fake(config('filesystems.media_disk'));
        $order = $this->order();

        $this->postJson('/api/v1/decant-please/orders/payment-proof', [
            'tracking_code' => $order->tracking_code,
            'phone' => $order->phone,
            'proof' => UploadedFile::fake()->image('transfer.jpg'),
        ])
            ->assertOk()
            ->assertJsonPath('has_payment_proof', true)
            ->assertJsonPath('payment_status', 'unpaid'); // upload doesn't mark paid

        $order->refresh();
        $this->assertNotNull($order->payment_proof_path);
        // the private proofs disk — and never the public media disk (#47)
        Storage::disk($disk)->assertExists($order->payment_proof_path);
        Storage::disk(config('filesystems.media_disk'))->assertMissing($order->payment_proof_path);
        // still unpaid — the decanter confirms separately
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
    }

    public function test_stored_proof_path_never_appears_in_public_api_responses(): void
    {
        $this->fakeProofsDisk();
        $order = $this->order();

        $upload = $this->postJson('/api/v1/decant-please/orders/payment-proof', [
            'tracking_code' => $order->tracking_code,
            'phone' => $order->phone,
            'proof' => UploadedFile::fake()->image('transfer.jpg'),
        ])->assertOk();

        $track = $this->getJson('/api/v1/decant-please/orders/track?'.http_build_query([
            'tracking_code' => $order->tracking_code,
            'phone' => $order->phone,
        ]))->assertOk()->assertJsonPath('has_payment_proof', true);

        // The receipt says a proof exists — never where it lives. basename() is
        // the random stored filename, immune to JSON slash-escaping of the path.
        $filename = basename($order->refresh()->payment_proof_path);
        $upload->assertDontSee($filename);
        $track->assertDontSee($filename);
        $upload->assertDontSee('payment_proof_path');
        $track->assertDontSee('payment_proof_path');
    }

    public function test_payment_proof_requires_the_exact_code_and_phone_pair(): void
    {
        $this->fakeProofsDisk();
        $order = $this->order();

        $this->postJson('/api/v1/decant-please/orders/payment-proof', [
            'tracking_code' => $order->tracking_code,
            'phone' => '09-000000000', // wrong phone
            'proof' => UploadedFile::fake()->image('transfer.jpg'),
        ])->assertNotFound();

        $this->assertNull($order->fresh()->payment_proof_path);
    }

    public function test_payment_proof_rejects_a_non_image(): void
    {
        $this->fakeProofsDisk();
        $order = $this->order();

        $this->postJson('/api/v1/decant-please/orders/payment-proof', [
            'tracking_code' => $order->tracking_code,
            'phone' => $order->phone,
            'proof' => UploadedFile::fake()->create('transfer.pdf', 100, 'application/pdf'),
        ])->assertJsonValidationErrors('proof');
    }

    public function test_reuploading_replaces_the_previous_screenshot(): void
    {
        $disk = $this->fakeProofsDisk();
        $order = $this->order();

        $this->postJson('/api/v1/decant-please/orders/payment-proof', [
            'tracking_code' => $order->tracking_code, 'phone' => $order->phone,
            'proof' => UploadedFile::fake()->image('first.jpg'),
        ])->assertOk();
        $first = $order->fresh()->payment_proof_path;

        $this->postJson('/api/v1/decant-please/orders/payment-proof', [
            'tracking_code' => $order->tracking_code, 'phone' => $order->phone,
            'proof' => UploadedFile::fake()->image('second.jpg'),
        ])->assertOk();
        $second = $order->fresh()->payment_proof_path;

        $this->assertNotSame($first, $second);
        Storage::disk($disk)->assertMissing($first);
        Storage::disk($disk)->assertExists($second);
    }

    public function test_tracking_receipt_reports_payment_status_and_balance_due(): void
    {
        $order = $this->order(total: 55000, deposit: 5000);
        $order->markPaid();

        $this->getJson('/api/v1/decant-please/orders/track?'.http_build_query([
            'tracking_code' => $order->tracking_code,
            'phone' => $order->phone,
        ]))
            ->assertOk()
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('balance_due_mmk', 50000);
    }

    // ---- Admin proof route (private disk, #47) -----------------------------

    public function test_payment_proof_route_redirects_guests_to_login(): void
    {
        $disk = $this->fakeProofsDisk();
        $order = $this->order();
        $order->attachPaymentProof(UploadedFile::fake()->image('proof.jpg')->store('payment-proofs', $disk));

        $response = $this->get(route('filament.admin.orders.payment-proof', ['order' => $order]));

        $response->assertRedirect();
        $this->assertStringContainsString('/admin/login', $response->headers->get('Location'));
    }

    public function test_admin_streams_the_proof_from_the_private_disk(): void
    {
        $this->actingAsAdmin();
        $disk = $this->fakeProofsDisk();
        $order = $this->order();
        $path = UploadedFile::fake()->image('proof.jpg')->store('payment-proofs', $disk);
        $order->attachPaymentProof($path);

        $response = $this->get(route('filament.admin.orders.payment-proof', ['order' => $order]));

        $response->assertOk();
        $this->assertStringStartsWith('image/', $response->headers->get('Content-Type'));
        $this->assertSame(Storage::disk($disk)->get($path), $response->streamedContent());
    }

    public function test_payment_proof_route_404s_when_the_order_has_no_proof(): void
    {
        $this->actingAsAdmin();
        $this->fakeProofsDisk();

        $this->get(route('filament.admin.orders.payment-proof', ['order' => $this->order()]))
            ->assertNotFound();
    }

    public function test_fresh_start_deletes_proof_objects(): void
    {
        // decant:fresh-start bulk-deletes orders (no model events), so it must
        // wipe the proofs directory itself.
        $disk = $this->fakeProofsDisk();
        Storage::fake(config('filesystems.media_disk'));
        $order = $this->order();
        $path = UploadedFile::fake()->image('proof.jpg')->store('payment-proofs', $disk);
        $order->attachPaymentProof($path);

        $this->artisan('decant:fresh-start', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Order::count());
        Storage::disk($disk)->assertMissing($path);
    }

    // ---- Admin -------------------------------------------------------------

    public function test_admin_can_mark_an_order_paid_then_unpaid(): void
    {
        $this->actingAsAdmin();
        $order = $this->order(status: OrderStatus::Pending);

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->callTableAction('markPaid', $order);
        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertNotNull($order->paid_at);

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->callTableAction('markUnpaid', $order);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
    }

    public function test_admin_can_filter_by_payment_status(): void
    {
        $this->actingAsAdmin();
        $unpaid = $this->order(status: OrderStatus::Pending);
        $paid = $this->order(status: OrderStatus::Pending);
        $paid->markPaid();

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->set('tableFilters.payment_status.value', PaymentStatus::Unpaid->value)
            ->assertCanSeeTableRecords([$unpaid])
            ->assertCanNotSeeTableRecords([$paid]);
    }

    public function test_dashboard_shows_unpaid_count_and_outstanding_amount(): void
    {
        $this->actingAsAdmin();
        $this->order(status: OrderStatus::Pending, total: 55000, deposit: 5000); // owes 50,000

        Livewire::test(OrderStats::class)
            ->assertSee('Unpaid orders')
            ->assertSee('50,000 Ks');
    }

    // ---- helpers -----------------------------------------------------------

    /** Fake the private proofs disk and return its name ("local" under test). */
    private function fakeProofsDisk(): string
    {
        $disk = config('filesystems.proofs_disk');
        Storage::fake($disk);

        return $disk;
    }

    private function order(
        OrderStatus $status = OrderStatus::AwaitingConfirmation,
        int $total = 55000,
        int $deposit = 0,
    ): Order {
        return Order::create([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'order_from' => 'website',
            'status' => $status,
            'total_mmk' => $total,
            'deposit_mmk' => $deposit,
        ])->refresh();
    }

    private function actingAsAdmin(): void
    {
        $this->actingAs($this->studioUser());
    }
}

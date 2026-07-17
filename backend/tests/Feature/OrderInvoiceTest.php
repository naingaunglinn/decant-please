<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Brand;
use App\Models\DecantPrice;
use App\Models\Order;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderInvoiceTest extends TestCase
{
    use RefreshDatabase;

    // Deliberately no actingAs() in setUp: the auth-redirect test needs a guest.

    public function test_invoice_route_redirects_guests_to_login(): void
    {
        $order = $this->fulfillableOrder();

        $response = $this->get(route('filament.admin.orders.invoice', $order));

        $response->assertRedirect();
        $this->assertStringContainsString('/admin/login', $response->headers->get('Location'));
    }

    public function test_invoice_route_streams_inline_a5_pdf(): void
    {
        $this->actingAs($this->admin());
        $order = $this->fulfillableOrder();

        $response = $this->get(route('filament.admin.orders.invoice', $order));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("invoice-{$order->tracking_code}.pdf", $response->headers->get('Content-Disposition'));

        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        // True A5 in points (148 × 210mm = 419.53 × 595.28pt), not a scaled A4/Letter.
        $this->assertStringContainsString('419.53', $pdf);
        $this->assertStringContainsString('595.28', $pdf);
    }

    public function test_invoice_route_404s_for_non_fulfillable_orders(): void
    {
        $this->actingAs($this->admin());

        $awaiting = $this->fulfillableOrder(OrderStatus::AwaitingConfirmation);
        $rejected = $this->fulfillableOrder(OrderStatus::Rejected);

        $this->get(route('filament.admin.orders.invoice', $awaiting))->assertNotFound();
        $this->get(route('filament.admin.orders.invoice', $rejected))->assertNotFound();
    }

    public function test_invoice_actions_visible_only_for_fulfillable_orders(): void
    {
        $this->actingAs($this->admin());

        $pending = $this->fulfillableOrder();
        $awaiting = $this->fulfillableOrder(OrderStatus::AwaitingConfirmation);

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->assertTableActionVisible('printInvoice', $pending)
            ->assertTableActionVisible('downloadInvoice', $pending)
            ->assertTableActionHidden('printInvoice', $awaiting)
            ->assertTableActionHidden('downloadInvoice', $awaiting);
    }

    public function test_download_invoice_action_streams_pdf(): void
    {
        $this->actingAs($this->admin());
        $order = $this->fulfillableOrder();

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->callTableAction('downloadInvoice', $order)
            ->assertFileDownloaded("invoice-{$order->tracking_code}.pdf")
            ->assertOk();
    }

    public function test_bulk_invoice_download_streams_one_pdf(): void
    {
        $this->actingAs($this->admin());
        $this->fulfillableOrder();
        $this->fulfillableOrder();
        $this->fulfillableOrder(OrderStatus::AwaitingConfirmation);

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->callTableAction('downloadInvoices')
            ->assertFileDownloaded('invoices-'.now()->format('Y-m-d').'.pdf')
            ->assertOk();
    }

    public function test_bulk_pdf_renders_one_a5_page_per_order(): void
    {
        $orders = collect([
            $this->fulfillableOrder(),
            $this->fulfillableOrder(),
            $this->fulfillableOrder(OrderStatus::Delivered),
        ])->each->loadMissing('items');

        $pdf = Pdf::loadView('pdf.invoice', ['orders' => $orders])->setPaper('a5')->output();

        // "/Type /Page" (not "/Pages") appears once per rendered page.
        preg_match_all('~/Type /Page[^s]~', $pdf, $pages);
        $this->assertCount(3, $pages[0]);
        $this->assertStringContainsString('419.53', $pdf);
    }

    public function test_invoice_shows_financials_with_conditional_rows_and_balance_due(): void
    {
        // 2 × 55,000 items + 3,000 fee − 5,000 promo discount = 108,000 total;
        // 50,000 deposit already taken → 58,000 still to collect at handover.
        $order = $this->fulfillableOrder(OrderStatus::Pending, [
            'delivery_fee_mmk' => 3000,
            'discount_mmk' => 5000,
            'deposit_mmk' => 50000,
            'promo_code' => 'RAINYSZN',
        ], quantity: 2);

        $html = view('pdf.invoice', ['order' => $order])->render();

        $this->assertStringContainsString('Order #'.$order->id, $html);
        $this->assertStringContainsString($order->tracking_code, $html);
        $this->assertStringContainsString($order->status->label(), $html);
        $this->assertStringContainsString('110,000 Ks', $html);        // subtotal
        $this->assertStringContainsString('Delivery fee', $html);
        $this->assertStringContainsString('Discount (RAINYSZN)', $html);
        $this->assertStringContainsString('Deposit received', $html);
        $this->assertStringContainsString('108,000 Ks', $html);        // total
        $this->assertStringContainsString('Balance due', $html);
        $this->assertStringContainsString('58,000 Ks', $html);         // total − deposit
    }

    public function test_invoice_hides_zero_rows_and_balance_never_goes_negative(): void
    {
        // Deposit larger than total (decanter over-collected / hand-edited):
        // balance clamps to zero rather than telling the runner to pay the customer.
        $order = $this->fulfillableOrder(OrderStatus::Pending, ['deposit_mmk' => 99999999]);

        $html = view('pdf.invoice', ['order' => $order])->render();

        $this->assertStringNotContainsString('Delivery fee', $html);
        $this->assertStringNotContainsString('Discount', $html);
        $this->assertStringContainsString('Balance due', $html);
        $this->assertStringContainsString('0 Ks', $html);
    }

    public function test_invoice_renders_burmese_customer_and_address(): void
    {
        $order = $this->fulfillableOrder(OrderStatus::Pending, [
            'customer_name' => 'ဒေါ်မြသန်း',
            'address' => 'အမှတ် ၄၅၊ ဗိုလ်ချုပ်လမ်း၊ ရန်ကုန်',
        ]);

        $html = view('pdf.invoice', ['order' => $order])->render();
        $this->assertStringContainsString('ဒေါ်မြသန်း', $html);
        $this->assertStringContainsString('ဗိုလ်ချုပ်လမ်း', $html);

        // The whole point of bundling Padauk: the PDF pipeline must accept the
        // Burmese text without an exception and embed the face that has the glyphs.
        $pdf = Pdf::loadView('pdf.invoice', ['order' => $order])->setPaper('a5')->output();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('Padauk', $pdf);
    }

    public function test_invoice_regenerates_fresh_after_an_edit(): void
    {
        $order = $this->fulfillableOrder();
        $this->assertStringNotContainsString('Delivery fee', view('pdf.invoice', ['order' => $order])->render());

        $order->delivery_fee_mmk = 4500;
        $order->recalculateTotal();

        $html = view('pdf.invoice', ['order' => $order->refresh()->loadMissing('items')])->render();
        $this->assertStringContainsString('Delivery fee', $html);
        $this->assertStringContainsString('4,500 Ks', $html);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@decantplease.local',
            'password' => 'secret-password',
        ]);
    }

    private function catalogPrice(): DecantPrice
    {
        return DecantPrice::firstOr(function () {
            $brand = Brand::create(['name' => 'Chanel', 'type' => 'designer']);
            $fragrance = $brand->fragrances()->create([
                'name' => 'Allure Homme Sport',
                'concentration' => 'cologne',
                'gender' => 'male',
            ]);

            return $fragrance->decantPrices()->create(['size_ml' => 10, 'price_mmk' => 55000]);
        });
    }

    private function fulfillableOrder(
        OrderStatus $status = OrderStatus::Pending,
        array $attributes = [],
        int $quantity = 1,
    ): Order {
        $price = $this->catalogPrice();

        $order = Order::create([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'order_from' => 'tiktok',
            'status' => $status,
            ...$attributes,
        ]);

        $order->items()->create([
            'fragrance_id' => $price->fragrance_id,
            'fragrance_name_snapshot' => 'Chanel Allure Homme Sport',
            'size_ml' => $price->size_ml,
            'unit_price_mmk' => $price->price_mmk,
            'quantity' => $quantity,
        ]);

        $order->recalculateTotal();

        return $order->refresh()->loadMissing('items');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Widgets\OrderStats;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * #67 — record the amount received at payment confirmation so balance due is real.
 * Covers the backend half (fixes 2–5): the Mark-paid capture into deposit_mmk, the
 * discount ≤ item-subtotal rule, the signed balanceDue() shared arithmetic, and the
 * balance-based "Balance outstanding" stat. The storefront's signed/overpaid handling
 * shipped separately in PR #70.
 */
class PaymentReceivedTest extends TestCase
{
    use RefreshDatabase;

    // ---- Amount-received capture (fix 2) ----------------------------------

    public function test_amount_received_default_online_is_the_discounted_item_subtotal(): void
    {
        // 60,000 items − 10,000 discount = 50,000; the fee is not part of an online prepay.
        $order = $this->orderWithLines(itemsTotal: 60000, discount: 10000, fee: 3000, method: PaymentMethod::Online);

        $this->assertSame(50000, $order->amountReceivedDefault());
    }

    public function test_amount_received_default_cod_adds_the_delivery_fee(): void
    {
        // COD collects everything the courier hands over: 60,000 − 10,000 + 3,000 = 53,000.
        $order = $this->orderWithLines(itemsTotal: 60000, discount: 10000, fee: 3000, method: PaymentMethod::Cod);

        $this->assertSame(53000, $order->amountReceivedDefault());
    }

    public function test_mark_paid_captures_the_amount_into_the_deposit(): void
    {
        $order = $this->orderWithLines(itemsTotal: 50000);

        $order->markPaid(50000);

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(50000, $order->deposit_mmk);
        $this->assertNotNull($order->paid_at);
    }

    public function test_a_downward_correction_of_the_amount_persists(): void
    {
        // The pre-fill is a convenience, not a floor — a customer who transferred less
        // than expected must be recordable, so a lower figure saves.
        $order = $this->orderWithLines(itemsTotal: 50000, deposit: 50000);

        $order->markPaid(30000);

        $this->assertSame(30000, $order->refresh()->deposit_mmk);
    }

    public function test_mark_unpaid_leaves_the_captured_amount(): void
    {
        $order = $this->orderWithLines(itemsTotal: 50000);
        $order->markPaid(40000);

        $order->markUnpaid();

        $order->refresh();
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertNull($order->paid_at);
        $this->assertSame(40000, $order->deposit_mmk); // not zeroed
    }

    // ---- The Mark-paid action (UI wiring) ---------------------------------

    public function test_the_mark_paid_action_records_the_entered_amount(): void
    {
        $this->actingAs($this->studioUser());
        $order = $this->orderWithLines(itemsTotal: 50000, status: OrderStatus::Pending);

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->callTableAction('markPaid', $order, ['amount_received' => 42000]);

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(42000, $order->deposit_mmk);
    }

    public function test_the_mark_paid_prefill_never_lowers_an_existing_deposit(): void
    {
        $this->actingAs($this->studioUser());
        // Existing deposit 45,000 > computed default 40,000 (online, no discount): with no
        // data passed the action fills its default max(existing, computed) = 45,000.
        $order = $this->orderWithLines(itemsTotal: 40000, deposit: 45000, method: PaymentMethod::Online, status: OrderStatus::Pending);

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->callTableAction('markPaid', $order);

        $this->assertSame(45000, $order->refresh()->deposit_mmk);
    }

    // ---- Discount ≤ item subtotal on the order form (fix 3) ---------------

    public function test_a_discount_exceeding_the_item_subtotal_is_rejected(): void
    {
        $this->actingAs($this->studioUser());

        $base = [
            'customer_name' => 'Ma Thiri',
            'phone' => '09-771234561',
            'address' => 'Yangon',
            'order_from' => 'tiktok',
            'status' => 'pending',
            'decant_date' => today()->addDay()->toDateString(),
            'delivery_fee_mmk' => 0,
            'deposit_mmk' => 0,
            'items' => [[
                'fragrance_id' => $this->itemFragrance()->id,
                'size_ml' => 10,
                'unit_price_mmk' => 20000,
                'quantity' => 1,
            ]],
        ];

        Livewire::test(CreateOrder::class)
            ->fillForm($base + ['discount_mmk' => 30000]) // > 20,000 subtotal
            ->call('create')
            ->assertHasFormErrors(['discount_mmk']);

        Livewire::test(CreateOrder::class)
            ->fillForm($base + ['discount_mmk' => 20000]) // == subtotal, allowed
            ->call('create')
            ->assertHasNoFormErrors();
    }

    // ---- Signed balanceDue() (fix 4) --------------------------------------

    public function test_balance_due_is_signed_and_equals_total_minus_deposit_when_conforming(): void
    {
        // Conforming (discount ≤ subtotal): the primitive equals total_mmk − deposit.
        $owed = $this->orderWithLines(itemsTotal: 50000, discount: 10000, fee: 3000, deposit: 10000);
        $this->assertSame(43000, $owed->total_mmk);          // 50,000 − 10,000 + 3,000
        $this->assertSame(33000, $owed->balanceDue());       // 43,000 − 10,000
        $this->assertSame($owed->total_mmk - $owed->deposit_mmk, $owed->balanceDue());

        $this->assertSame(0, $this->orderWithLines(itemsTotal: 50000, deposit: 50000)->balanceDue());
        $this->assertSame(-10000, $this->orderWithLines(itemsTotal: 50000, deposit: 60000)->balanceDue());
    }

    // ---- Invoice: collect vs overpaid (fix 4) -----------------------------

    public function test_invoice_prints_the_signed_balance_and_matches_the_model(): void
    {
        $owed = $this->orderWithLines(itemsTotal: 50000, deposit: 20000); // balance 30,000
        $html = view('pdf.invoice', ['order' => $owed->loadMissing('items')])->render();
        $this->assertStringContainsString('Balance due', $html);
        $this->assertStringContainsString('30,000 Ks', $html);
        $this->assertStringNotContainsString('Overpaid by', $html);

        $overpaid = $this->orderWithLines(itemsTotal: 50000, deposit: 65000); // −15,000
        $html = view('pdf.invoice', ['order' => $overpaid->loadMissing('items')])->render();
        $this->assertStringContainsString('Overpaid by', $html);
        $this->assertStringContainsString('15,000 Ks', $html);
    }

    // ---- OrderStats "Balance outstanding" (fix 5) -------------------------

    public function test_outstanding_counts_positive_balances_and_excludes_overpaid_and_closed(): void
    {
        $this->actingAs($this->studioUser());

        // Paid, but a COD fee is still due: 50,000 + 3,000 fee − 20,000 = 33,000 — counted
        // even though payment_status is "paid" (the stat keys off the balance, not status).
        $partial = $this->orderWithLines(itemsTotal: 50000, fee: 3000, deposit: 20000, method: PaymentMethod::Cod, status: OrderStatus::Pending);
        $partial->markPaid(20000);

        // Overpaid — negative balance, excluded (never netted against what's owed).
        $this->orderWithLines(itemsTotal: 50000, deposit: 60000, status: OrderStatus::Pending);

        // Cancelled — never counted, whatever its balance.
        $cancelled = $this->orderWithLines(itemsTotal: 999000, status: OrderStatus::AwaitingConfirmation);
        $cancelled->cancel();

        Livewire::test(OrderStats::class)
            ->assertSee('Balance outstanding')
            ->assertSee('33,000 Ks')     // the partial's balance, exactly
            ->assertDontSee('999,000')   // cancelled excluded
            ->assertDontSee('23,000');   // 33,000 − 10,000 overpaid, if it wrongly netted
    }

    // ---- Tracking receipt signed contract ---------------------------------

    public function test_tracking_receipt_reports_a_signed_negative_balance_when_overpaid(): void
    {
        $order = $this->orderWithLines(itemsTotal: 40000);
        $order->markPaid(55000); // overpaid by 15,000

        $this->getJson('/api/v1/decant-please/orders/track?'.http_build_query([
            'tracking_code' => $order->tracking_code,
            'phone' => $order->phone,
        ]))
            ->assertOk()
            ->assertJsonPath('balance_due_mmk', -15000);
    }

    // ---- Courier settle credits the collected cash (#67 review gap) --------

    public function test_courier_settle_credits_the_collected_fee_and_clears_an_online_order(): void
    {
        $this->actingAs($this->studioUser());
        // Online: customer transferred the 50,000 items; the 3,000 fee rides the courier.
        $order = $this->orderWithLines(itemsTotal: 50000, fee: 3000, method: PaymentMethod::Online, status: OrderStatus::Delivered);
        $order->markPaid(50000);
        $this->assertSame(3000, $order->balanceDue());

        $order->handToCourier(today(), 3000);
        $order->settleCourier(today(), 3000); // courier collected the fee in full

        $order->refresh();
        $this->assertSame(0, $order->balanceDue());
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);

        // …and it drops out of "Balance outstanding" (the symptom that surfaced this).
        // Assert the count, not the amount: "3,000 Ks" is a substring of the "53,000 Ks"
        // revenue stat, so assertDontSee on it would false-fail.
        Livewire::test(OrderStats::class)
            ->assertSee('Balance outstanding')
            ->assertSee('0 order(s) with a balance due');
    }

    public function test_courier_settle_of_a_cod_order_clears_the_balance_and_marks_it_paid(): void
    {
        // COD, never marked paid: the courier collecting IS the payment.
        $order = $this->orderWithLines(itemsTotal: 50000, fee: 3000, method: PaymentMethod::Cod, status: OrderStatus::Delivered);
        $this->assertSame(53000, $order->balanceDue());
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);

        $order->handToCourier(today(), 53000);
        $order->settleCourier(today(), 53000);

        $order->refresh();
        $this->assertSame(0, $order->balanceDue());
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertNotNull($order->paid_at);
    }

    public function test_a_failed_delivery_settled_with_zero_leaves_the_balance_and_next_handoff(): void
    {
        $order = $this->orderWithLines(itemsTotal: 50000, fee: 3000, method: PaymentMethod::Cod, status: OrderStatus::Delivered);
        $order->handToCourier(today(), 53000);

        $order->settleCourier(today(), 0); // nothing collected

        $order->refresh();
        $this->assertSame(53000, $order->balanceDue());                 // unchanged
        $this->assertSame(0, $order->deposit_mmk);                      // nothing credited
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame(53000, max(0, $order->balanceDue()));         // next handoff's default, unchanged
    }

    public function test_a_partial_collection_leaves_the_remainder_unpaid_and_the_next_handoff_carries_it(): void
    {
        $order = $this->orderWithLines(itemsTotal: 50000, fee: 3000, method: PaymentMethod::Cod, status: OrderStatus::Delivered);
        $order->handToCourier(today(), 53000);

        $order->settleCourier(today(), 20000); // courier collected only part

        $order->refresh();
        $this->assertSame(33000, $order->balanceDue());                 // 53,000 − 20,000 remainder
        $this->assertSame(20000, $order->deposit_mmk);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame(33000, max(0, $order->balanceDue()));         // a re-handoff carries only the remainder
    }

    public function test_an_over_collection_at_settle_reads_as_overpaid(): void
    {
        // No cap on the entered amount — a genuine overpayment is honoured, per #67.
        $order = $this->orderWithLines(itemsTotal: 50000, fee: 3000, method: PaymentMethod::Cod, status: OrderStatus::Delivered);
        $order->handToCourier(today(), 53000);

        $order->settleCourier(today(), 60000);

        $order->refresh();
        $this->assertSame(-7000, $order->balanceDue());
        $this->assertSame(PaymentStatus::Paid, $order->payment_status); // balance ≤ 0 → paid
    }

    public function test_the_courier_settled_action_defaults_to_the_carried_balance_and_credits_it(): void
    {
        $this->actingAs($this->studioUser());
        $order = $this->orderWithLines(itemsTotal: 50000, fee: 3000, method: PaymentMethod::Online, status: OrderStatus::Delivered);
        $order->markPaid(50000);
        $order->handToCourier(today(), 3000);

        // no data → the action fills its default min(carried, balance) = 3,000
        Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->callTableAction('courierSettled', $order);

        $order->refresh();
        $this->assertSame(0, $order->balanceDue());
        $this->assertNotNull($order->courier_settled_at);
    }

    // ---- helper -----------------------------------------------------------

    private function orderWithLines(
        int $itemsTotal,
        int $discount = 0,
        int $fee = 0,
        int $deposit = 0,
        PaymentMethod $method = PaymentMethod::Online,
        OrderStatus $status = OrderStatus::Pending,
    ): Order {
        $order = Order::create([
            'customer_name' => 'Ma Thiri',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'order_from' => 'website',
            'status' => $status,
            'payment_method' => $method->value,
            'delivery_fee_mmk' => $fee,
            'discount_mmk' => $discount,
            'deposit_mmk' => $deposit,
        ]);

        $order->items()->create([
            'fragrance_id' => $this->itemFragrance()->id,
            'fragrance_name_snapshot' => 'Fixture Brand Fixture',
            'size_ml' => 10,
            'unit_price_mmk' => $itemsTotal,
            'quantity' => 1,
        ]);

        $order->recalculateTotal(); // total_mmk = max(0, itemsTotal + fee − discount)

        return $order->refresh();
    }
}

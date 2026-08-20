<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Widgets\CourierFloat;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class CourierFloatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->studioUser());
    }

    public function test_handoff_snapshots_the_carrying_amount_and_settle_releases_it(): void
    {
        $order = $this->order(total: 65000, deposit: 15000);

        $order->handToCourier(today(), $order->balanceDue()); // 50,000

        $order->refresh();
        $this->assertTrue($order->handed_to_courier_at->isToday());
        $this->assertSame(50000, $order->courier_carrying_mmk);
        $this->assertNull($order->courier_settled_at);

        $order->settleCourier(today()->addDay());
        $this->assertTrue($order->refresh()->courier_settled_at->isTomorrow());
    }

    public function test_settling_an_unhanded_order_is_a_logic_error(): void
    {
        $this->expectException(LogicException::class);

        $this->order(total: 65000)->settleCourier(today());
    }

    public function test_marking_paid_after_handoff_never_shrinks_the_float(): void
    {
        $order = $this->order(total: 65000);
        $order->handToCourier(today(), $order->balanceDue()); // snapshot: 65,000

        // The courier reports the cash collected; the decanter marks paid before
        // the cash physically arrives. The float must not move.
        $order->markPaid();

        $this->assertSame(65000, $order->refresh()->courier_carrying_mmk);

        Livewire::test(CourierFloat::class)
            ->assertSee('Cash with couriers')
            ->assertSee('65,000 Ks');
    }

    public function test_float_sums_handed_unsettled_only_and_excludes_cancelled(): void
    {
        $this->order(total: 30000)->handToCourier(today()->subDays(3), 30000);
        $this->order(total: 45000)->handToCourier(today(), 45000);

        $settled = $this->order(total: 20000);
        $settled->handToCourier(today()->subDay(), 20000);
        $settled->settleCourier(today());

        // §4: a cancelled order's cash is a refund conversation, not float.
        $cancelledOut = $this->order(total: 99000, status: OrderStatus::AwaitingConfirmation);
        $cancelledOut->handed_to_courier_at = today();
        $cancelledOut->courier_carrying_mmk = 99000;
        $cancelledOut->save();
        $cancelledOut->cancel();

        Livewire::test(CourierFloat::class)
            ->assertSee('75,000 Ks')                                   // 30k + 45k
            ->assertSee('2 order(s) out — oldest handed off '.today()->subDays(3)->format('j M'))
            ->assertDontSee('99,000 Ks')                               // cancelled absent
            ->assertDontSee('174,000 Ks');                             // the sum if it leaked in
    }

    public function test_quiet_float_reads_nothing_outstanding(): void
    {
        $this->order(total: 65000); // never handed off

        Livewire::test(CourierFloat::class)
            ->assertSee('0 Ks')
            ->assertSee('Nothing outstanding');
    }

    private function order(int $total, int $deposit = 0, OrderStatus $status = OrderStatus::Decanted): Order
    {
        return Order::create([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'order_from' => 'website',
            'status' => $status,
            'deposit_mmk' => $deposit,
            'total_mmk' => $total,
        ]);
    }
}

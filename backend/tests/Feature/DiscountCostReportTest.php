<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Widgets\DiscountCost;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DiscountCostReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->studioUser());
    }

    public function test_sums_per_code_with_a_hand_edited_row_and_excludes_cancelled_and_rejected(): void
    {
        $this->order(status: OrderStatus::Pending, discount: 10000, code: 'SUMMER10');
        $this->order(status: OrderStatus::Delivered, discount: 15000, code: 'SUMMER10');
        $this->order(status: OrderStatus::Pending, discount: 8000, code: 'WELCOME');
        $this->order(status: OrderStatus::Pending, discount: 5000, code: null); // hand-edited
        $this->order(status: OrderStatus::Pending, discount: 0, code: null);    // no discount — no row

        // §4: cancelled and rejected never count in a money figure — asserted, not assumed.
        $this->order(status: OrderStatus::Cancelled, discount: 7000, code: 'SUMMER10');
        $this->order(status: OrderStatus::Rejected, discount: 3000, code: 'GONE');

        Livewire::test(DiscountCost::class)
            ->assertSee('SUMMER10')
            ->assertSee('25,000 Ks')            // 10,000 + 15,000 — cancelled 7,000 absent
            ->assertSee('WELCOME')
            ->assertSee('8,000 Ks')
            ->assertSee('Hand-edited (no code)')
            ->assertSee('5,000 Ks')
            ->assertSee('38,000 Ks')            // total row
            ->assertDontSee('32,000 Ks')        // SUMMER10 if the cancelled order leaked in
            ->assertDontSee('GONE');            // rejected code never appears at all
    }

    public function test_quiet_month_shows_the_empty_state(): void
    {
        $this->order(status: OrderStatus::Pending, discount: 0, code: null);

        Livewire::test(DiscountCost::class)
            ->assertSee('No discounts given this month.');
    }

    private function order(OrderStatus $status, int $discount, ?string $code): Order
    {
        return Order::create([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'order_from' => 'website',
            'status' => $status,
            'discount_mmk' => $discount,
            'promo_code' => $code,
            'total_mmk' => 90000 - $discount,
        ]);
    }
}

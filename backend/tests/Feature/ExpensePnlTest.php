<?php

namespace Tests\Feature;

use App\Enums\ExpenseCategory;
use App\Enums\OrderStatus;
use App\Filament\Pages\ProfitAndLoss;
use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Models\Brand;
use App\Models\Expense;
use App\Models\Fragrance;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpensePnlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'admin@decantplease.local',
            'password' => 'secret-password',
        ]));
    }

    public function test_only_stock_purchase_is_non_operating(): void
    {
        foreach (ExpenseCategory::cases() as $category) {
            $this->assertSame(
                $category !== ExpenseCategory::StockPurchase,
                $category->isOperating(),
                "isOperating() wrong for {$category->value}",
            );
        }
    }

    public function test_monthly_pnl_math_with_the_no_double_count_rules(): void
    {
        // Order A: 90,000 items, 10,000 discount, 3,000 fee — fully costed at 30,000.
        $this->order(items: 90000, cost: 30000, discount: 10000, fee: 3000);
        // Order B: 50,000 items, 2,000 fee — uncosted (in M, not N).
        $this->order(items: 50000, cost: null, fee: 2000);
        // Cancelled order: §4 — contributes nothing anywhere.
        $cancelled = $this->order(items: 70000, cost: 20000, discount: 999, status: OrderStatus::AwaitingConfirmation);
        $cancelled->cancel();

        Expense::create(['spent_on' => today(), 'category' => 'packaging', 'amount_mmk' => 5000]);
        Expense::create(['spent_on' => today(), 'category' => 'delivery', 'amount_mmk' => 4000]);
        Expense::create(['spent_on' => today(), 'category' => 'marketing', 'amount_mmk' => 6000]);
        Expense::create(['spent_on' => today(), 'category' => 'stock_purchase', 'amount_mmk' => 300000]);
        Expense::create(['spent_on' => today()->addMonthNoOverflow()->startOfMonth(), 'category' => 'fees', 'amount_mmk' => 7000]);

        $pnl = \App\Support\MonthlyPnl::for(today()->year, today()->month);

        $this->assertSame(130000, $pnl->salesIncomeMmk);        // (90k−10k) + 50k — cancelled absent
        $this->assertSame(10000, $pnl->discountsGivenMmk);      // not 10,999 — §4 holds
        $this->assertSame(30000, $pnl->cogsMmk);
        $this->assertSame(1, $pnl->costedOrders);
        $this->assertSame(2, $pnl->totalOrders);
        $this->assertSame(100000, $pnl->grossMarginMmk);
        $this->assertSame(11000, $pnl->operatingTotalMmk);      // packaging + marketing — delivery lives in its own line
        $this->assertSame(5000, $pnl->deliveryFeesCollectedMmk);
        $this->assertSame(4000, $pnl->courierPaidMmk);
        $this->assertSame(1000, $pnl->deliveryResultMmk);
        $this->assertSame(90000, $pnl->netOperatingMmk);        // 100k − 11k + 1k
        $this->assertSame(300000, $pnl->stockPurchasesMmk);     // below the line, not in net
    }

    public function test_month_boundaries_are_engine_proof(): void
    {
        Expense::create(['spent_on' => today()->startOfMonth(), 'category' => 'other', 'amount_mmk' => 1000]);
        Expense::create(['spent_on' => today()->endOfMonth(), 'category' => 'other', 'amount_mmk' => 2000]);
        Expense::create(['spent_on' => today()->addMonthNoOverflow()->startOfMonth(), 'category' => 'other', 'amount_mmk' => 4000]);

        $inMonth = $this->order(items: 10000, cost: null);
        $inMonth->created_at = today()->endOfMonth()->setTime(23, 59, 59);
        $inMonth->save();

        $nextMonth = $this->order(items: 20000, cost: null);
        $nextMonth->created_at = today()->addMonthNoOverflow()->startOfMonth()->setTime(0, 0, 1);
        $nextMonth->save();

        $pnl = \App\Support\MonthlyPnl::for(today()->year, today()->month);

        $this->assertSame(3000, $pnl->operatingTotalMmk);  // 1k + 2k; next month's 4k excluded
        $this->assertSame(10000, $pnl->salesIncomeMmk);    // the 23:59:59 order counts; next month's doesn't
    }

    public function test_pnl_page_renders_and_steps_months(): void
    {
        $this->order(items: 90000, cost: 30000, discount: 10000, fee: 3000);
        Expense::create(['spent_on' => today(), 'category' => 'marketing', 'amount_mmk' => 6000]);

        Livewire::test(ProfitAndLoss::class)
            ->assertSee('Net operating profit')
            ->assertSee('80,000 Ks')            // income 90k − 10k
            ->assertSee('On 1 of 1 orders')
            ->assertSee('47,000 Ks')            // net: 50k gross − 6k opex + 3k delivery result
            ->call('previousMonth')
            ->assertSee('On 0 of 0 orders');    // a quiet month renders honestly, not blank
    }

    public function test_expense_create_via_the_resource_page(): void
    {
        Livewire::test(ManageExpenses::class)
            ->callAction('create', data: [
                'spent_on' => today()->toDateString(),
                'category' => 'packaging',
                'amount_mmk' => 5000,
                'note' => '100 vials from Mingalar Market',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('expenses', ['category' => 'packaging', 'amount_mmk' => 5000]);
    }

    private function order(int $items, ?int $cost, int $discount = 0, int $fee = 0, OrderStatus $status = OrderStatus::Pending): Order
    {
        $order = Order::create([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'order_from' => 'website',
            'status' => $status,
            'discount_mmk' => $discount,
            'delivery_fee_mmk' => $fee,
            'total_mmk' => max(0, $items + $fee - $discount),
        ]);

        $order->items()->create([
            'fragrance_id' => $this->fragranceId(),
            'fragrance_name_snapshot' => 'Creed Aventus',
            'size_ml' => 10,
            'unit_price_mmk' => $items,
            'unit_cost_mmk' => $cost,
            'quantity' => 1,
        ]);

        return $order;
    }

    /** One shared, deliberately UNcosted fragrance — so the creating-hook leaves
     *  a null unit_cost_mmk alone and explicit values pass through untouched. */
    private function fragranceId(): int
    {
        $brand = Brand::firstOrCreate(['name' => 'Creed'], ['type' => 'niche']);

        return Fragrance::firstOrCreate(
            ['brand_id' => $brand->id, 'name' => 'Aventus'],
            ['concentration' => 'edp', 'gender' => 'male'],
        )->id;
    }
}

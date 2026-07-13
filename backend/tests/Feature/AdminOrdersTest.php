<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Pages\ProductionSchedule;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Widgets\OrderStats;
use App\Filament\Widgets\RevenueChart;
use App\Filament\Widgets\TopFragrances;
use App\Filament\Widgets\UpcomingDecants;
use App\Models\Brand;
use App\Models\DecantPrice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminOrdersTest extends TestCase
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

    public function test_checkout_order_lands_in_needs_review_tab_with_tracking_code(): void
    {
        $awaiting = $this->checkoutOrder();
        $pending = $this->manualOrder(OrderStatus::Pending);

        $this->assertSame(OrderStatus::AwaitingConfirmation, $awaiting->status);
        $this->assertNotNull($awaiting->tracking_code);

        Livewire::test(ListOrders::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$awaiting])       // default tab = Needs review
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_accept_action_schedules_order_and_shows_on_production_schedule(): void
    {
        $order = $this->checkoutOrder(quantity: 1);
        $second = $this->checkoutOrder(quantity: 2);

        $decantDate = today()->addDay()->toDateString();

        $list = Livewire::test(ListOrders::class);
        foreach ([$order, $second] as $target) {
            $list->callTableAction('accept', $target, data: [
                'decant_date' => $decantDate,
                'delivery_date' => today()->addDays(2)->toDateString(),
            ]);
        }

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame($decantDate, $order->decant_date->toDateString());
        $this->assertSame(today()->addDays(2)->toDateString(), $order->delivery_date->toDateString());

        // both orders' items aggregate into one production line: 1 + 2 = 3 vials
        Livewire::test(ProductionSchedule::class)
            ->assertOk()
            ->assertSee('Chanel — Allure Homme Sport')
            ->assertSee('× 3')
            ->assertSee('2 order(s)');
    }

    public function test_csv_export_streams_current_table(): void
    {
        $order = $this->checkoutOrder(quantity: 2);

        Livewire::test(ListOrders::class)
            ->callTableAction('exportCsv')
            ->assertFileDownloaded('orders-'.now()->format('Y-m-d').'.csv')
            ->assertOk();

        $this->assertNotNull($order->tracking_code);
    }

    public function test_reject_action_stores_reason_and_is_excluded_from_revenue(): void
    {
        $order = $this->checkoutOrder(); // 55,000 Ks
        Order::create([
            'customer_name' => 'Paying Customer', 'phone' => '09-1', 'address' => 'Yangon',
            'status' => OrderStatus::Delivered, 'total_mmk' => 100000,
        ]);

        Livewire::test(ListOrders::class)
            ->callTableAction('reject', $order, data: [
                'reason' => 'Other',
                'other_reason' => 'Suspicious duplicate of an earlier order.',
            ]);

        $order->refresh();
        $this->assertSame(OrderStatus::Rejected, $order->status);
        $this->assertSame('Suspicious duplicate of an earlier order.', $order->rejection_reason);
        $this->assertNull($order->decant_date);

        Livewire::test(OrderStats::class)
            ->assertSee('100,000 Ks')       // revenue = delivered only
            ->assertDontSee('155,000 Ks');  // rejected 55k never counted
    }

    public function test_accept_and_reject_are_hidden_outside_awaiting_confirmation(): void
    {
        $pending = $this->manualOrder(OrderStatus::Pending);

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->assertTableActionHidden('accept', $pending)
            ->assertTableActionHidden('reject', $pending);
    }

    public function test_manual_order_requires_decant_date_and_starts_pending(): void
    {
        $price = $this->price();

        $form = [
            'customer_name' => 'Zaw Min',
            'phone' => '09-965432187',
            'address' => 'Pazundaung, Yangon',
            'order_from' => 'tiktok',
            'status' => 'pending',
            'delivery_fee_mmk' => 3000,
            'discount_mmk' => 1000,
            'deposit_mmk' => 0,
            'items' => [[
                'fragrance_id' => $price->fragrance_id,
                'size_ml' => 10,
                'unit_price_mmk' => 60000, // deliberately overrides the catalog's 55,000
                'quantity' => 2,
            ]],
        ];

        Livewire::test(CreateOrder::class)
            ->fillForm($form) // no decant_date
            ->call('create')
            ->assertHasFormErrors(['decant_date' => 'required']);

        Livewire::test(CreateOrder::class)
            ->fillForm($form + ['decant_date' => today()->addDay()->toDateString()])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = Order::where('customer_name', 'Zaw Min')->firstOrFail();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNotNull($order->tracking_code);
        $this->assertSame('Chanel Allure Homme Sport', $order->items->first()->fragrance_name_snapshot);
        $this->assertSame(122000, $order->total_mmk); // 60,000×2 + 3,000 − 1,000
    }

    public function test_order_financials_survive_catalog_price_changes(): void
    {
        $order = $this->checkoutOrder();
        $this->assertSame(55000, $order->total_mmk);

        DecantPrice::query()->update(['price_mmk' => 999999]);

        $order->refresh()->load('items');
        $this->assertSame(55000, $order->items->first()->unit_price_mmk);
        $this->assertSame(55000, $order->total_mmk);
    }

    public function test_todays_decants_tab_excludes_cancelled_and_rejected(): void
    {
        $due = $this->manualOrder(OrderStatus::Pending, decantDate: today());
        $cancelled = $this->manualOrder(OrderStatus::Cancelled, decantDate: today());

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'todays_decants')
            ->assertCanSeeTableRecords([$due])
            ->assertCanNotSeeTableRecords([$cancelled]);
    }

    public function test_production_schedule_shows_confirmed_empty_days_and_ignores_cancelled(): void
    {
        $this->manualOrder(OrderStatus::Cancelled, decantDate: today());

        Livewire::test(ProductionSchedule::class)
            ->assertOk()
            ->assertSee('Nothing to decant')
            ->assertDontSee('Chanel —');
    }

    public function test_remaining_dashboard_widgets_render_with_data(): void
    {
        $order = $this->checkoutOrder();
        $order->accept(today()->addDay(), today()->addDays(2));

        Livewire::test(RevenueChart::class)->assertOk();

        Livewire::test(TopFragrances::class)
            ->assertOk()
            ->assertSee('Chanel — Allure Homme Sport');

        Livewire::test(UpcomingDecants::class)
            ->assertOk()
            ->assertSee($order->customer_name)
            ->assertSee('Open production schedule');
    }

    private function price(): DecantPrice
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

    private function checkoutOrder(int $quantity = 1): Order
    {
        $price = $this->price();

        return Order::newFromCheckout([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'items' => [[
                'fragrance_id' => $price->fragrance_id,
                'size_ml' => $price->size_ml,
                'quantity' => $quantity,
            ]],
        ]);
    }

    private function manualOrder(OrderStatus $status, $decantDate = null): Order
    {
        return Order::create([
            'customer_name' => 'Manual Customer',
            'phone' => '09-700000000',
            'address' => 'Yangon',
            'order_from' => 'tiktok',
            'status' => $status,
            'decant_date' => $decantDate,
        ])->refresh();
    }
}

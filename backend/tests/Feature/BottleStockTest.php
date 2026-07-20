<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Resources\Fragrances\Pages\EditFragrance;
use App\Filament\Resources\Fragrances\Pages\ListFragrances;
use App\Filament\Resources\Fragrances\RelationManagers\BottlesRelationManager;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Bottle;
use App\Models\Brand;
use App\Models\Fragrance;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class BottleStockTest extends TestCase
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

    public function test_logging_a_bottle_recomputes_in_stock_per_size(): void
    {
        $fragrance = $this->fragrance('Allure Homme Sport');
        $untracked = $this->fragrance('Bleu de Chanel');
        $untracked->decantPrices()->where('size_ml', 30)->update(['in_stock' => false]);

        Bottle::logFor($fragrance, 20, today()->toDateString());

        $this->assertSame(
            [5 => true, 10 => true, 30 => false],
            $fragrance->decantPrices()->pluck('in_stock', 'size_ml')->all(),
            'Sizes the bottle can still cover stay in stock; a 30ml decant cannot come out of 20ml.'
        );

        // The untracked sibling keeps its manual flags exactly as they were.
        $this->assertSame(
            [5 => true, 10 => true, 30 => false],
            $untracked->decantPrices()->pluck('in_stock', 'size_ml')->all()
        );
    }

    public function test_accepting_an_order_pours_from_the_bottle_and_resyncs_stock(): void
    {
        $fragrance = $this->fragrance();
        Bottle::logFor($fragrance, 25, today()->toDateString());

        $this->checkoutOrder($fragrance, [[10, 1]])->accept(today()->addDay());

        $bottle = $fragrance->activeBottle()->first();
        $this->assertSame(15, $bottle->remaining_ml);
        $this->assertSame(
            [5 => true, 10 => true, 30 => false],
            $fragrance->decantPrices()->pluck('in_stock', 'size_ml')->all()
        );

        // A second pour drops it below 10ml — that size now goes out of stock too.
        $this->checkoutOrder($fragrance, [[10, 1]])->accept(today()->addDay());

        $this->assertSame(5, $bottle->refresh()->remaining_ml);
        $this->assertSame(
            [5 => true, 10 => false, 30 => false],
            $fragrance->decantPrices()->pluck('in_stock', 'size_ml')->all()
        );
    }

    public function test_accept_is_all_or_nothing_across_items(): void
    {
        // Well-stocked fragrance first in the order, the short one second —
        // a naive implementation would pour the first before failing on the second.
        $stocked = $this->fragrance('Allure Homme Sport');
        $short = $this->fragrance('Bleu de Chanel');
        Bottle::logFor($stocked, 100, today()->toDateString());
        Bottle::logFor($short, 20, today()->toDateString());

        $order = $this->checkoutOrder($stocked, [[10, 1]]);
        $order->items()->create([
            'fragrance_id' => $short->id,
            'fragrance_name_snapshot' => 'Chanel Bleu de Chanel',
            'size_ml' => 10,
            'unit_price_mmk' => 55000,
            'quantity' => 3, // needs 30ml, only 20ml left
        ]);

        try {
            $order->accept(today()->addDay());
            $this->fail('accept() should have refused an order the bottle cannot cover.');
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->implode(' ');
            $this->assertStringContainsString('Bleu de Chanel', $message);
            $this->assertStringContainsString('20ml', $message);
            $this->assertStringContainsString('30ml', $message);
        }

        $this->assertSame(100, $stocked->activeBottle()->first()->remaining_ml,
            'The well-stocked sibling item must not be partially applied.');
        $this->assertSame(20, $short->activeBottle()->first()->remaining_ml);

        $order->refresh();
        $this->assertSame(OrderStatus::AwaitingConfirmation, $order->status);
        $this->assertNull($order->decant_date);
    }

    public function test_untracked_fragrance_accepts_exactly_as_before(): void
    {
        $fragrance = $this->fragrance();
        $fragrance->decantPrices()->where('size_ml', 30)->update(['in_stock' => false]);

        $order = $this->checkoutOrder($fragrance, [[10, 2]]);
        $order->accept(today()->addDay());

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(0, Bottle::count());
        $this->assertSame(
            [5 => true, 10 => true, 30 => false],
            $fragrance->decantPrices()->pluck('in_stock', 'size_ml')->all(),
            'With no bottle logged, manual in_stock flags stay exactly as they were.'
        );
    }

    public function test_sizes_of_the_same_fragrance_pour_from_one_bottle(): void
    {
        $fragrance = $this->fragrance();
        Bottle::logFor($fragrance, 12, today()->toDateString());

        // 5ml + 10ml each fit alone, but together they need 15ml of the 12ml left.
        $order = $this->checkoutOrder($fragrance, [[5, 1], [10, 1]]);

        try {
            $order->accept(today()->addDay());
            $this->fail('Items of the same fragrance must be summed against one bottle.');
        } catch (ValidationException) {
            $this->assertSame(12, $fragrance->activeBottle()->first()->remaining_ml);
        }

        Bottle::logFor($fragrance, 20, today()->toDateString());
        $this->checkoutOrder($fragrance, [[5, 1], [10, 1]])->accept(today()->addDay());

        $this->assertSame(5, $fragrance->activeBottle()->first()->remaining_ml);
    }

    public function test_logging_a_second_bottle_starts_fresh_instead_of_adding_up(): void
    {
        $fragrance = $this->fragrance();
        $first = Bottle::logFor($fragrance, 50, today()->subDays(30)->toDateString());
        $this->checkoutOrder($fragrance, [[30, 1]])->accept(today()->addDay());
        $this->assertSame([5 => true, 10 => true, 30 => false],
            $fragrance->decantPrices()->pluck('in_stock', 'size_ml')->all());

        $second = Bottle::logFor($fragrance, 100, today()->toDateString());

        $this->assertFalse($first->refresh()->is_active);
        $this->assertSame(100, $second->remaining_ml, 'Not 120 — the old bottle\'s 20ml leftover never carries over.');
        $this->assertTrue($second->is_active);
        $this->assertSame([5 => true, 10 => true, 30 => true],
            $fragrance->decantPrices()->pluck('in_stock', 'size_ml')->all(),
            '30ml decants are back in stock off the fresh bottle.');
    }

    public function test_accept_action_surfaces_the_shortage_as_a_notification(): void
    {
        // Order placed first, bottle logged after — otherwise the sync would have
        // already pulled 10ml out of stock and checkout itself would refuse it.
        $fragrance = $this->fragrance();
        $order = $this->checkoutOrder($fragrance, [[10, 1]]);
        Bottle::logFor($fragrance, 8, today()->toDateString());

        Livewire::test(ListOrders::class)
            ->callTableAction('accept', $order, data: [
                'decant_date' => today()->addDay()->toDateString(),
                'delivery_date' => today()->addDays(2)->toDateString(),
            ])
            ->assertNotified('Not enough left in the bottle');

        $this->assertSame(OrderStatus::AwaitingConfirmation, $order->refresh()->status);
        $this->assertSame(8, $fragrance->activeBottle()->first()->remaining_ml);
    }

    public function test_fragrances_table_shows_bottle_stock_for_both_states(): void
    {
        $tracked = $this->fragrance('Allure Homme Sport');
        $this->fragrance('Bleu de Chanel'); // stays untracked
        Bottle::logFor($tracked, 100, today()->toDateString());
        $tracked->activeBottle()->update(['remaining_ml' => 42]);

        Livewire::test(ListFragrances::class)
            ->assertOk()
            ->assertSee('42 / 100 ml')
            ->assertSee('No bottle logged');
    }

    public function test_log_new_bottle_action_on_the_fragrance_edit_page(): void
    {
        $fragrance = $this->fragrance();

        Livewire::test(BottlesRelationManager::class, [
            'ownerRecord' => $fragrance,
            'pageClass' => EditFragrance::class,
        ])
            ->callTableAction('logNewBottle', data: [
                'total_ml' => 20,
                'opened_at' => today()->toDateString(),
            ])
            ->assertNotified();

        $bottle = $fragrance->activeBottle()->first();
        $this->assertSame(20, $bottle->total_ml);
        $this->assertSame(20, $bottle->remaining_ml);
        $this->assertSame([5 => true, 10 => true, 30 => false],
            $fragrance->decantPrices()->pluck('in_stock', 'size_ml')->all());
    }

    public function test_saving_the_fragrance_form_resyncs_new_sizes_from_the_bottle(): void
    {
        $fragrance = $this->fragrance();
        Bottle::logFor($fragrance, 20, today()->toDateString());

        Livewire::test(EditFragrance::class, ['record' => $fragrance->getRouteKey()])
            ->fillForm([
                'decantPrices' => [
                    ['size_ml' => 5, 'price_mmk' => 30000],
                    ['size_ml' => 10, 'price_mmk' => 55000],
                    ['size_ml' => 30, 'price_mmk' => 150000],
                    ['size_ml' => 50, 'price_mmk' => 220000],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            [5 => true, 10 => true, 30 => false, 50 => false],
            $fragrance->decantPrices()->pluck('in_stock', 'size_ml')->all(),
            'A size added while a bottle is tracked is computed immediately, not left at the default.'
        );
    }

    /** Chanel fragrance with the standard 5/10/30 sizes, all in stock. */
    private function fragrance(string $name = 'Allure Homme Sport'): Fragrance
    {
        $brand = Brand::firstOrCreate(['name' => 'Chanel'], ['type' => 'designer']);

        $fragrance = $brand->fragrances()->create([
            'name' => $name,
            'concentration' => 'cologne',
            'gender' => 'male',
        ]);

        foreach ([5 => 30000, 10 => 55000, 30 => 150000] as $size => $price) {
            $fragrance->decantPrices()->create(['size_ml' => $size, 'price_mmk' => $price]);
        }

        return $fragrance;
    }

    /** @param array<array{0: int, 1: int}> $items [[size_ml, quantity], ...] */
    private function checkoutOrder(Fragrance $fragrance, array $items): Order
    {
        return Order::newFromCheckout([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'items' => array_map(fn (array $item) => [
                'fragrance_id' => $fragrance->id,
                'size_ml' => $item[0],
                'quantity' => $item[1],
            ], $items),
        ]);
    }
}

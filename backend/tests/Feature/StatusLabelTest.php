<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ShopStatus;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopSetting;
use App\Support\TenantContext;
use App\Templates\Templates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step 39: the states are fixed, the words are the shop's. "prepared" reads
 * Decanted for a decant shop and Packed for clothing — through one resolver
 * (OrderStatus::label() → Templates::statusLabel) on every surface — and the
 * decanted → prepared data migration moves no value but the status string.
 */
class StatusLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_shop_reads_prepared_in_its_own_templates_word(): void
    {
        $decant = app(TenantContext::class)->get();
        $clothing = Shop::create(['name' => 'Yangon Threads', 'slug' => 'yangon-threads', 'status' => ShopStatus::Live]);
        Templates::assignToShop($clothing, 'clothing');

        $this->assertSame('Decanted', OrderStatus::Prepared->label());
        $this->assertSame('Decanted', OrderStatus::Prepared->getLabel()); // the Filament badge + select
        $this->assertSame('Pending', OrderStatus::Pending->label());

        app(TenantContext::class)->set($clothing);
        $this->assertSame('Packed', OrderStatus::Prepared->label());
        $this->assertSame('Packed', Templates::statusLabels()['prepared']);
        $this->assertSame('Delivered', OrderStatus::Delivered->label());

        // back to the first shop: its own word, not the last shop's
        app(TenantContext::class)->set($decant);
        $this->assertSame('Decanted', OrderStatus::Prepared->label());

        // no tenant (a console command): the category-free word, never a throw
        app(TenantContext::class)->set(null);
        $this->assertSame('Prepared', OrderStatus::Prepared->label());
    }

    public function test_tracking_sends_the_shops_words_prep_date_and_the_decant_date_alias(): void
    {
        ShopSetting::current()->update(['template' => 'clothing']);
        $order = $this->order(OrderStatus::Prepared);

        $this->getJson("/api/v1/decant-please/orders/track?tracking_code={$order->tracking_code}&phone=09-771234561")
            ->assertOk()
            ->assertJsonPath('status', 'prepared')
            ->assertJsonPath('status_label', 'Packed')
            ->assertJsonPath('status_labels.prepared', 'Packed')
            ->assertJsonPath('status_labels.pending', 'Pending')
            ->assertJsonPath('prep_date', '2026-10-01')
            ->assertJsonPath('decant_date', '2026-10-01');
    }

    public function test_a_decant_shops_tracking_still_says_decanted(): void
    {
        $order = $this->order(OrderStatus::Prepared);

        $this->getJson("/api/v1/decant-please/orders/track?tracking_code={$order->tracking_code}&phone=09-771234561")
            ->assertOk()
            ->assertJsonPath('status', 'prepared')
            ->assertJsonPath('status_label', 'Decanted')
            ->assertJsonPath('status_labels.prepared', 'Decanted');
    }

    public function test_the_invoice_prints_the_shops_word(): void
    {
        $order = $this->order(OrderStatus::Prepared);
        $this->assertStringContainsString('<strong>Decanted</strong>', view('pdf.invoice', ['order' => $order])->render());

        $clothing = Shop::create(['name' => 'Yangon Threads', 'slug' => 'yangon-threads', 'status' => ShopStatus::Live]);
        Templates::assignToShop($clothing, 'clothing');
        app(TenantContext::class)->set($clothing);
        $packed = $this->order(OrderStatus::Prepared);

        $html = view('pdf.invoice', ['order' => $packed])->render();
        $this->assertStringContainsString('<strong>Packed</strong>', $html);
        $this->assertStringNotContainsString('Decanted', $html);
    }

    public function test_the_orders_list_tab_and_date_column_use_the_shops_words(): void
    {
        $this->actingAs($this->studioUser());
        ShopSetting::current()->update(['template' => 'clothing']);
        $this->order(OrderStatus::Prepared);

        Livewire::test(ListOrders::class)
            ->assertSee('Packed')
            ->assertSee('Packing date')
            ->assertDontSee('Decanted')
            ->set('activeTab', 'prepared')
            ->assertCanSeeTableRecords(Order::where('status', OrderStatus::Prepared)->get());

        Livewire::test(EditOrder::class, ['record' => Order::first()->getRouteKey()])
            ->assertSee('Packing date')
            ->assertSee('The day you pack this order.')
            ->assertDontSee('physically decant');
    }

    public function test_the_migration_round_trips_on_seeded_rows_and_moves_only_the_status_string(): void
    {
        $first = app(TenantContext::class)->get();
        $this->order(OrderStatus::Prepared);
        $this->order(OrderStatus::Pending);
        $this->order(OrderStatus::Delivered);

        $other = Shop::create(['name' => 'Other', 'slug' => 'other', 'status' => ShopStatus::Live]);
        app(TenantContext::class)->set($other);
        $this->order(OrderStatus::Prepared);
        app(TenantContext::class)->set($first);

        $columns = ['id', 'shop_id', 'total_mmk', 'deposit_mmk', 'delivery_fee_mmk', 'discount_mmk', 'delivery_date', 'updated_at'];
        $before = DB::table('orders')->orderBy('id')->get([...$columns, 'status', 'prep_date'])->map(fn (object $row) => (array) $row)->all();

        $migration = require database_path('migrations/2026_09_29_000000_rename_decanted_to_prepared_on_orders.php');
        $migration->down();

        $this->assertTrue(Schema::hasColumn('orders', 'decant_date'));
        $this->assertFalse(Schema::hasColumn('orders', 'prep_date'));
        $down = DB::table('orders')->orderBy('id')->get([...$columns, 'status', 'decant_date'])->map(fn (object $row) => (array) $row)->all();

        foreach ($before as $i => $row) {
            $expected = $row;
            $expected['status'] = $row['status'] === 'prepared' ? 'decanted' : $row['status'];
            $expected['decant_date'] = $expected['prep_date'];
            unset($expected['prep_date']);

            $this->assertEquals($expected, $down[$i]);
        }
        $this->assertSame(['decanted', 'pending', 'delivered', 'decanted'], array_column($down, 'status'));

        $migration->up();

        $after = DB::table('orders')->orderBy('id')->get([...$columns, 'status', 'prep_date'])->map(fn (object $row) => (array) $row)->all();
        $this->assertSame($before, $after);
    }

    private function order(OrderStatus $status): Order
    {
        return Order::create([
            'customer_name' => 'Su Su', 'phone' => '09-771234561', 'address' => 'Yangon',
            'order_from' => 'tiktok', 'status' => $status, 'prep_date' => '2026-10-01',
            'delivery_date' => '2026-10-02', 'total_mmk' => 55000, 'deposit_mmk' => 10000,
            'discount_mmk' => 0, 'delivery_fee_mmk' => 0,
        ]);
    }
}

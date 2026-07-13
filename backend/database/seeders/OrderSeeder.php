<?php

namespace Database\Seeders;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\DecantPrice;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $today = CarbonImmutable::today();

        // Website checkouts, through the real checkout path — land as awaiting_confirmation.
        $this->checkout('Aung Kyaw', '09-771234561', 'No. 12, Baho Road, Sanchaung, Yangon',
            [['Sauvage', 10, 1], ['Baccarat Rouge 540', 5, 1]], 'Please decant from a fresh batch.');
        $this->checkout('Su Myat Noe', '09-952345672', 'Room 502, Diamond Condo, Kamayut, Yangon',
            [['Delina', 5, 2]]);
        $this->checkout('Thiha Zaw', '09-421987653', '88 Strand Road, Kyauktada, Yangon',
            [['Aventus', 10, 1], ['Layton', 5, 1]]);

        // A rejected website order, through the real transition.
        $this->checkout('Nay Lin Aung', '09-799887766', '45 Inya Road, Bahan, Yangon', [['Eros', 5, 1]])
            ->reject('Bottle ran out this week — restocking next month.');

        // Accepted website orders (now pending), one decanting today for the production schedule.
        $this->checkout('Khin Thandar', '09-263748596', '23 U Wisara Road, Dagon, Yangon',
            [['Coco Mademoiselle', 10, 1], ['Libre', 5, 1]])
            ->accept($today->addDay(), $today->addDays(3));
        $this->checkout('Hnin Wai', '09-450012389', '7 Mile, Pyay Road, Mayangone, Yangon',
            [['Allure Homme Sport', 10, 2], ['Grand Soir', 5, 1]])
            ->accept($today, $today->addDays(2));

        // Manual admin entries for DM-sourced orders — accepted on entry, so pending or later.
        $manual = [
            ['Zaw Min Htet', '09-965432187', '19 Bogyoke Road, Pazundaung, Yangon', OrderSource::Tiktok,
                OrderStatus::Pending, $today, $today->addDays(2), 20000, 3000, 0, null,
                [['Le Male Elixir', 10, 1]]],
            ['Ei Phyu Phyu', '09-773216549', '5 Kabar Aye Pagoda Road, Yankin, Yangon', OrderSource::Facebook,
                OrderStatus::Decanted, $today->subDay(), $today->addDay(), 0, 2500, 0, 'Wants gift wrapping if possible.',
                [['Y', 10, 1], ['Bleu de Chanel', 5, 1]]],
            ['Min Khant', '09-421119876', '31 Anawrahta Road, Lanmadaw, Yangon', OrderSource::Facebook,
                OrderStatus::Delivered, $today->subDays(6), $today->subDays(4), 0, 3000, 5000, null,
                [['Layton', 10, 1]]],
            ['May Thu Kyaw', '09-952224466', '12 Thanlwin Road, Golden Valley, Yangon', OrderSource::Other,
                OrderStatus::Delivered, $today->subDays(10), $today->subDays(8), 0, 2000, 0, 'Repeat customer.',
                [['Miss Dior', 5, 2]]],
            ['Kyaw Gyi', '09-788990011', '2 Waizayantar Road, Thingangyun, Yangon', OrderSource::Tiktok,
                OrderStatus::Cancelled, $today->subDays(3), null, 0, 0, 0, 'Customer stopped replying.',
                [['Dylan Blue', 10, 1]]],
        ];

        foreach ($manual as [$name, $phone, $address, $source, $status, $decantDate, $deliveryDate, $deposit, $fee, $discount, $note, $items]) {
            $order = Order::create([
                'customer_name' => $name,
                'phone' => $phone,
                'address' => $address,
                'order_from' => $source,
                'status' => $status,
                'decant_date' => $decantDate,
                'delivery_date' => $deliveryDate,
                'deposit_mmk' => $deposit,
                'delivery_fee_mmk' => $fee,
                'discount_mmk' => $discount,
                'notes' => $note,
            ]);

            foreach ($items as [$fragranceName, $size, $quantity]) {
                $price = $this->price($fragranceName, $size);

                $order->items()->create([
                    'fragrance_id' => $price->fragrance_id,
                    'fragrance_name_snapshot' => $price->fragrance->brand->name.' '.$price->fragrance->name,
                    'size_ml' => $price->size_ml,
                    'unit_price_mmk' => $price->price_mmk,
                    'quantity' => $quantity,
                ]);
            }

            $order->recalculateTotal();
        }
    }

    /**
     * @param  array<array{0: string, 1: int, 2: int}>  $items  [fragrance name, size_ml, quantity]
     */
    private function checkout(string $name, string $phone, string $address, array $items, ?string $note = null): Order
    {
        return Order::newFromCheckout([
            'customer_name' => $name,
            'phone' => $phone,
            'address' => $address,
            'notes' => $note,
            'items' => collect($items)->map(fn (array $item) => [
                'fragrance_id' => $this->price($item[0], $item[1])->fragrance_id,
                'size_ml' => $item[1],
                'quantity' => $item[2],
            ])->all(),
        ]);
    }

    private function price(string $fragranceName, int $sizeMl): DecantPrice
    {
        return DecantPrice::query()
            ->whereHas('fragrance', fn ($query) => $query->where('name', $fragranceName))
            ->where('size_ml', $sizeMl)
            ->with('fragrance.brand')
            ->firstOrFail();
    }
}

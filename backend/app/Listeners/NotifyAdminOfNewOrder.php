<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Support\Money;
use App\Support\TelegramNotifier;

/**
 * Push a new-order alert to the decanter's Telegram. Runs synchronously (the
 * queue is `sync` by default), but TelegramNotifier is bounded and swallow-all,
 * so a slow/failing Telegram never delays or breaks the checkout response.
 */
class NotifyAdminOfNewOrder
{
    public function __construct(private TelegramNotifier $telegram) {}

    public function handle(OrderPlaced $event): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        $order = $event->order->loadMissing('items');

        $items = $order->items
            ->map(fn ($item) => "{$item->size_ml}ml × {$item->quantity} {$item->fragrance_name_snapshot}")
            ->implode(', ');

        $adminUrl = rtrim((string) config('app.url'), '/')."/admin/orders/{$order->id}/edit";

        $message = implode("\n", [
            "🆕 New order #{$order->id}",
            "{$order->customer_name} · {$order->phone}",
            $items,
            'Total: '.Money::kyat($order->total_mmk),
            "Track: {$order->tracking_code}",
            $adminUrl,
        ]);

        $this->telegram->sendToAdmin($message);
    }
}

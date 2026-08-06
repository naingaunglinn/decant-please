<?php

namespace App\Listeners;

use App\Enums\PaymentMethod;
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

        // The method, never payment_status — every order is unpaid at placement,
        // so status carries no signal here. For online, say whether the checkout
        // slip already rode in ("awaited" is defensive: checkout validation
        // requires the slip today, but the listener shouldn't assume its caller).
        $method = $order->payment_method ?? PaymentMethod::Cod;
        $payment = 'Payment: '.$method->label();

        if ($method !== PaymentMethod::Cod) {
            $payment .= $order->payment_proof_path ? ' — slip attached' : ' — slip awaited';
        }

        $message = implode("\n", [
            "🆕 New order #{$order->id}",
            "{$order->customer_name} · {$order->phone}",
            $items,
            'Total: '.Money::kyat($order->total_mmk),
            $payment,
            "Track: {$order->tracking_code}",
            $adminUrl,
        ]);

        $this->telegram->sendToAdmin($message);
    }
}

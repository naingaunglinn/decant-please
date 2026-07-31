<?php

namespace App\Listeners;

use App\Events\PaymentProofUploaded;
use App\Support\Money;
use App\Support\TelegramNotifier;

/**
 * Tell the decanter a transfer slip landed on an order. Link only, never the
 * image: proofs live on the private bucket by design (#47), and a multi-MB
 * sendPhoto upload wouldn't fit TelegramNotifier's 5s bound. Same contract as
 * the new-order listener — synchronous but bounded and swallow-all, so a slow
 * or failing Telegram never fails the customer's upload.
 */
class NotifyAdminOfPaymentProof
{
    public function __construct(private TelegramNotifier $telegram) {}

    public function handle(PaymentProofUploaded $event): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        $order = $event->order;

        $headline = $event->isReplacement
            ? "🧾 Payment slip replaced — order #{$order->id}"
            : "🧾 Payment slip uploaded — order #{$order->id}";

        $adminUrl = rtrim((string) config('app.url'), '/')."/admin/orders/{$order->id}/edit";

        $message = implode("\n", [
            $headline,
            "{$order->customer_name} · {$order->phone}",
            'Total: '.Money::kyat($order->total_mmk).' · Balance due: '.Money::kyat($order->balanceDue()),
            "Track: {$order->tracking_code}",
            $adminUrl,
        ]);

        $this->telegram->sendToAdmin($message);
    }
}

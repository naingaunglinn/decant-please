<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer placed a website order. Dispatched from the checkout endpoint after
 * the order commits — the decoupling point so notification channels (Telegram
 * now; SMS/Viber later) hang off the event, not off the checkout code.
 */
class OrderPlaced
{
    use Dispatchable;

    public function __construct(public Order $order) {}
}

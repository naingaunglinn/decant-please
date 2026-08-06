<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer attached a transfer slip via the standalone payment-proof endpoint.
 * Dispatched from PaymentProofController only, after the proof write commits —
 * never from checkout: a checkout slip is already reported inside the new-order
 * alert, so dispatching from both paths would double-send the same slip.
 * isReplacement marks a re-upload (the endpoint overwrites), so the alert can
 * say so instead of buzzing the decanter identically for a blurry-photo retry.
 */
class PaymentProofUploaded
{
    use Dispatchable;

    public function __construct(public Order $order, public bool $isReplacement) {}
}

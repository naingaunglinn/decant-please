<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams an order's payment-proof screenshot to the admin. Registered inside
 * the Filament panel's authenticated routes (see AdminPanelProvider) — the
 * panel's own auth middleware guards it, never this controller by hand.
 *
 * The proofs disk is private by design (no custom domain, no url, no public
 * access — see config/filesystems.php), so this route is the only way a proof
 * is ever served: Laravel reads the object server-side and streams it inline.
 * No presigned or public URL exists anywhere, which is also why the proofs
 * bucket needs no CORS policy — the browser only ever fetches this same-origin
 * route, never the bucket.
 */
class PaymentProofViewController extends Controller
{
    public function __invoke(Order $order): StreamedResponse
    {
        $disk = Storage::disk(config('filesystems.proofs_disk'));

        abort_unless(
            $order->payment_proof_path !== null && $disk->exists($order->payment_proof_path),
            404
        );

        return $disk->response($order->payment_proof_path);
    }
}

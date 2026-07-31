<?php

namespace App\Http\Controllers\Api;

use App\Events\PaymentProofUploaded;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer uploads a screenshot of their offline transfer. Gated by the same
 * exact tracking_code + phone pair as tracking/cancel — no guessing oracle — and,
 * like cancel, only meaningful while the order isn't already settled. Uploading
 * proof does NOT mark the order paid: the decanter still eyeballs the screenshot
 * and confirms in the admin. This just moves the screenshot out of DMs and onto
 * the order.
 */
class PaymentProofController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tracking_code' => ['required', 'string', 'max:32'],
            'phone' => ['required', 'string', 'max:32'],
            // jpeg/png/webp only, 4MB — a phone screenshot, generously sized
            'proof' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
        ]);

        $order = Order::findByTracking($data['tracking_code'], $data['phone']);

        if (! $order) {
            return TrackOrderController::notFoundResponse();
        }

        $hadProof = $order->payment_proof_path !== null;

        // The private proofs disk, never the public media disk. Replacing an
        // earlier upload cleans up the old object via the model's updated hook.
        $path = $request->file('proof')->store('payment-proofs', config('filesystems.proofs_disk'));

        $order->attachPaymentProof($path);

        // After the write commits. This endpoint is the event's only dispatch
        // site — a checkout slip is already reported by the new-order alert.
        PaymentProofUploaded::dispatch($order, isReplacement: $hadProof);

        return response()->json(TrackOrderController::receipt($order));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Events\PaymentProofUploaded;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer uploads a screenshot of their offline transfer. Gated by the same
 * exact tracking_code + phone pair as tracking/cancel — no guessing oracle — and,
 * like cancel, refused with a 409 once the order is settled (paid, cancelled or
 * rejected — Order::acceptsPaymentProof). Uploading
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

        // After the generic 404 (the oracle rule), before anything is stored.
        if (! $order->acceptsPaymentProof()) {
            return response()->json([
                'message' => "This order doesn't need a payment slip any more — call us if something's wrong.",
            ], 409);
        }

        $hadProof = $order->payment_proof_path !== null;

        // The private proofs disk, never the public media disk, under the shop's
        // own shops/{id}/ prefix (step 32). Replacing an earlier upload cleans up
        // the old object via the model's updated hook.
        $path = $request->file('proof')->store(
            'shops/'.app(TenantContext::class)->id().'/payment-proofs',
            config('filesystems.proofs_disk'),
        );

        $order->attachPaymentProof($path);

        // After the write commits. This endpoint is the event's only dispatch
        // site — a checkout slip is already reported by the new-order alert.
        PaymentProofUploaded::dispatch($order, isReplacement: $hadProof);

        return response()->json(TrackOrderController::receipt($order));
    }
}

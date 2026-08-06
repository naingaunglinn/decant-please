<?php

namespace App\Http\Controllers\Api;

use App\Events\OrderPlaced;
use App\Http\Controllers\Controller;
use App\Models\DeliveryTownship;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Honeypot first, before validation: a bot that filled the hidden field
        // gets the pretend-success whatever shape it posted — a 422 naming the
        // new address fields would tell it exactly what a real payload needs.
        if (filled($request->input('website'))) {
            Log::info('Checkout honeypot triggered — order silently dropped.', ['ip' => $request->ip()]);

            // pretend success: same shape, nothing persisted
            return response()->json([
                'tracking_code' => Order::generateTrackingCode(),
                'total_mmk' => 0,
                'total_formatted' => Money::kyat(0),
                'delivery_fee_mmk' => 0,
                'delivery_fee_formatted' => Money::kyat(0),
                'promo_note' => null,
            ], 201);
        }

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            // The address is structured now: a serviceable township (which the
            // fee derives from) plus a street line. `address` itself is
            // composed server-side and no longer accepted from the client —
            // and a client-sent delivery_fee_mmk is simply never read.
            'delivery_township_id' => ['required', 'integer'],
            'address_line' => ['required', 'string', 'max:500'],
            'address_extra' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
            'promo_code' => ['nullable', 'string', 'max:64'],
            'payment_method' => ['nullable', 'string', 'in:cod,online'], // defaults to cod
            // Online orders prepay + attach their transfer slip up front; COD doesn't.
            'proof' => [
                Rule::requiredIf(fn (): bool => $request->input('payment_method') === 'online'),
                'image', 'mimes:jpeg,jpg,png,webp', 'max:4096',
            ],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.fragrance_id' => ['required', 'integer'],
            'items.*.size_ml' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        // One check covers unknown, deactivated, and courier-less townships —
        // each must fail loudly here, never fall through to a 0 fee.
        $township = DeliveryTownship::query()->serviceable()->find($data['delivery_township_id']);

        if (! $township) {
            throw ValidationException::withMessages([
                'delivery_township_id' => "We can't deliver to that township just now — pick another from the list.",
            ]);
        }

        $order = Order::newFromCheckout([
            'customer_name' => $data['customer_name'],
            'phone' => $data['phone'],
            'delivery_township' => $township,
            'address_line' => $data['address_line'],
            'address_extra' => $data['address_extra'] ?? null,
            'notes' => $data['note'] ?? null,
            'promo_code' => $data['promo_code'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'items' => $data['items'],
        ]);

        // Online prepay: the transfer slip rides in with the checkout, so the order
        // is born with its proof on the private disk (never the public media disk).
        if ($request->hasFile('proof')) {
            $order->attachPaymentProof(
                $request->file('proof')->store('payment-proofs', config('filesystems.proofs_disk')),
            );
        }

        // After the order commits (newFromCheckout's transaction has returned) —
        // notification channels hang off this, never off the checkout code.
        OrderPlaced::dispatch($order);

        return response()->json([
            'tracking_code' => $order->tracking_code,
            'total_mmk' => $order->total_mmk,
            'total_formatted' => Money::kyat($order->total_mmk),
            // what was actually charged for delivery, so order-complete shows
            // it rather than re-deriving from the zones tree
            'delivery_fee_mmk' => $order->delivery_fee_mmk,
            'delivery_fee_formatted' => Money::kyat($order->delivery_fee_mmk),
            // set only when a promo lapsed between preview and submission
            'promo_note' => $order->promoNote,
        ], 201);
    }
}

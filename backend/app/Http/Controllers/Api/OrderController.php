<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'promo_code' => ['nullable', 'string', 'max:64'],
            'website' => ['nullable', 'string', 'max:255'], // honeypot — real customers never see it
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.fragrance_id' => ['required', 'integer'],
            'items.*.size_ml' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        if (filled($data['website'] ?? null)) {
            Log::info('Checkout honeypot triggered — order silently dropped.', ['ip' => $request->ip()]);

            // pretend success: same shape, nothing persisted
            return response()->json([
                'tracking_code' => Order::generateTrackingCode(),
                'total_mmk' => 0,
                'total_formatted' => Money::kyat(0),
                'promo_note' => null,
            ], 201);
        }

        $order = Order::newFromCheckout([
            'customer_name' => $data['customer_name'],
            'phone' => $data['phone'],
            'address' => $data['address'],
            'notes' => $data['note'] ?? null,
            'promo_code' => $data['promo_code'] ?? null,
            'items' => $data['items'],
        ]);

        return response()->json([
            'tracking_code' => $order->tracking_code,
            'total_mmk' => $order->total_mmk,
            'total_formatted' => Money::kyat($order->total_mmk),
            // set only when a promo lapsed between preview and submission
            'promo_note' => $order->promoNote,
        ], 201);
    }
}

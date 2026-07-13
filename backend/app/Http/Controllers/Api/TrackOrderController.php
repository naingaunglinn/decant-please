<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TrackOrderController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tracking_code' => ['required', 'string', 'max:32'],
            'phone' => ['required', 'string', 'max:32'],
        ]);

        $order = Order::query()
            ->where('tracking_code', Str::upper(trim($data['tracking_code'])))
            ->where('phone', trim($data['phone']))
            ->with('items.fragrance.brand')
            ->first();

        if (! $order) {
            // same response whichever field was wrong — no guessing oracle
            return response()->json([
                'message' => "We couldn't find an order with that code and phone number.",
            ], 404);
        }

        return response()->json([
            'tracking_code' => $order->tracking_code,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'placed_at' => $order->created_at->toIso8601String(),
            'decant_date' => $order->decant_date?->toDateString(),
            'delivery_date' => $order->delivery_date?->toDateString(),
            'rejection_reason' => $order->rejection_reason,
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'fragrance_name' => "{$item->fragrance->brand->name} — {$item->fragrance->name} ({$item->fragrance->concentration->label()})",
                'size_ml' => $item->size_ml,
                'quantity' => $item->quantity,
            ]),
            'total_formatted' => Money::kyat($order->total_mmk),
        ]);
    }
}

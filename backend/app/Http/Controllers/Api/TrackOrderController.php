<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackOrderController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tracking_code' => ['required', 'string', 'max:32'],
            'phone' => ['required', 'string', 'max:32'],
        ]);

        $order = Order::findByTracking($data['tracking_code'], $data['phone']);

        if (! $order) {
            return self::notFoundResponse();
        }

        return response()->json(self::receipt($order));
    }

    /** Same response whichever field was wrong — no guessing oracle. */
    public static function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'message' => "We couldn't find an order with that code and phone number.",
        ], 404);
    }

    /**
     * The full receipt payload — shared by tracking and cancellation so the
     * customer always sees one consistent shape. Raw integers for money; the
     * frontend owns Kyat formatting (total_formatted kept for compatibility).
     */
    public static function receipt(Order $order): array
    {
        return [
            'tracking_code' => $order->tracking_code,
            'order_number' => "#{$order->id}",
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'placed_at' => $order->created_at->toIso8601String(),
            'decant_date' => $order->decant_date?->toDateString(),
            'delivery_date' => $order->delivery_date?->toDateString(),
            'rejection_reason' => $order->rejection_reason,
            'customer_name' => $order->customer_name,
            'phone' => $order->phone,
            'address' => $order->address,
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'fragrance_name' => "{$item->fragrance->brand->name} — {$item->fragrance->name} ({$item->fragrance->concentration->label()})",
                'size_ml' => $item->size_ml,
                'quantity' => $item->quantity,
                'unit_price_mmk' => $item->unit_price_mmk,
                'line_total_mmk' => $item->line_total_mmk,
            ]),
            'subtotal_mmk' => (int) $order->items->sum('line_total_mmk'),
            'delivery_fee_mmk' => $order->delivery_fee_mmk,
            'discount_mmk' => $order->discount_mmk,
            'promo_code' => $order->promo_code,
            'deposit_mmk' => $order->deposit_mmk,
            'total_mmk' => $order->total_mmk,
            'total_formatted' => Money::kyat($order->total_mmk),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PromoCode;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ValidatePromoController extends Controller
{
    /** Preview only — nothing persisted, nothing incremented. Checkout re-evaluates. */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.fragrance_id' => ['required', 'integer'],
            'items.*.size_ml' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        // re-derive the subtotal from the current catalog — never trust a client sum
        $subtotal = 0;
        foreach ($data['items'] as $i => $item) {
            $price = Order::currentPriceFor((int) $item['fragrance_id'], (int) $item['size_ml']);

            if (! $price) {
                throw ValidationException::withMessages([
                    "items.{$i}" => Order::unavailableItemMessage($item),
                ]);
            }

            $subtotal += $price->price_mmk * $item['quantity'];
        }

        $result = PromoCode::evaluate($data['code'], $subtotal);

        return response()->json([
            'valid' => $result['valid'],
            'discount_mmk' => $result['discount_mmk'],
            'discount_formatted' => Money::kyat($result['discount_mmk']),
            'new_total_formatted' => Money::kyat(max(0, $subtotal - $result['discount_mmk'])),
            'message' => $result['message'],
        ]);
    }
}

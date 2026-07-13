<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CancelOrderController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tracking_code' => ['required', 'string', 'max:32'],
            'phone' => ['required', 'string', 'max:32'],
        ]);

        $order = Order::findByTracking($data['tracking_code'], $data['phone']);

        if (! $order) {
            return TrackOrderController::notFoundResponse();
        }

        if ($order->status !== OrderStatus::AwaitingConfirmation) {
            return response()->json([
                'message' => "This order's already being prepared — call to cancel or change it.",
            ], 409);
        }

        $order->cancel();

        return response()->json(TrackOrderController::receipt($order));
    }
}

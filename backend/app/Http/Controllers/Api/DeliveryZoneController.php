<?php

namespace App\Http\Controllers\Api;

use App\Enums\Region;
use App\Http\Controllers\Controller;
use App\Models\DeliveryTownship;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The whole serviceable destination tree in one response, so the checkout's
 * township select filters client-side — no request between the two selects.
 * Deliberately its own endpoint, not folded into /meta: /meta rides every
 * catalog page, and a few hundred zone rows on every shop view is payload
 * for nothing.
 *
 * Nothing about couriers crosses this boundary — no names, aliases, costs,
 * or which courier serves a township (the v19 rule extended to supplier
 * data). Serviceability is applied as a filter, so a dead zone simply never
 * appears; district is omitted too, since it drives no customer decision.
 */
class DeliveryZoneController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(Cache::remember('api.delivery-zones', 600, function (): array {
            $townships = DeliveryTownship::query()
                ->serviceable()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            $regions = [];

            foreach (Region::cases() as $region) {
                $rows = $townships->where('region', $region)->values();

                if ($rows->isEmpty()) {
                    continue;
                }

                $regions[] = [
                    'value' => $region->value,
                    'label' => $region->label(),
                    // ->all(): cache plain arrays — a Collection doesn't survive
                    // the cache store's hardened unserialize (the /meta lesson)
                    'townships' => $rows->map(fn (DeliveryTownship $township): array => [
                        'id' => $township->id,
                        'name' => $township->name,
                        'name_mm' => $township->name_mm,
                        'label' => $township->optionLabel(),
                        'fee_mmk' => $township->fee_mmk,
                        'fee_formatted' => Money::kyat($township->fee_mmk),
                    ])->all(),
                ];
            }

            return ['regions' => $regions];
        }));
    }
}

<?php

namespace App\Models;

use App\Enums\Courier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * One courier's coverage of one township: their own spelling of the place
 * (the reconciliation alias), their recorded cost (reference only — never in
 * any money figure), and whether the route is currently open. Suspended
 * (is_available false — route closed, fee and cost kept) is deliberately not
 * the same state as unserved (no row): only one of them reverses.
 */
#[Fillable(['delivery_township_id', 'courier', 'courier_name', 'cost_mmk', 'is_available'])]
class DeliveryTownshipCourier extends Model
{
    protected static function booted(): void
    {
        // A courier row appearing, closing, or reopening flips the parent
        // township's serviceability — which is exactly what /delivery-zones
        // filters on, so it busts the same cache the township does.
        static::saved(fn () => Cache::forget('api.delivery-zones'));
        static::deleted(fn () => Cache::forget('api.delivery-zones'));
    }

    public function township(): BelongsTo
    {
        return $this->belongsTo(DeliveryTownship::class, 'delivery_township_id');
    }

    protected function casts(): array
    {
        return [
            'courier' => Courier::class,
            'cost_mmk' => 'integer',
            'is_available' => 'boolean',
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\Region;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * A delivery destination and the one customer-facing fee for it. Which courier
 * carries the parcel (and at what cost) lives on the child rows — the customer
 * never sees any of that; they pick a destination and see a fee.
 *
 * fee_mmk of 0 is a real free-delivery zone, never "not priced yet" —
 * is_active is the only gate (spec 30, Decision 10).
 */
#[Fillable(['region', 'district', 'name', 'name_mm', 'fee_mmk', 'is_active', 'sort_order'])]
class DeliveryTownship extends Model
{
    protected static function booted(): void
    {
        // /delivery-zones caches the serviceable tree for 10 minutes — drop it
        // the moment a township changes, so checkout reflects it right away
        // (the v14 ShopSetting precedent). Courier rows bust it too, from
        // their own model: serviceability is derived from both tables.
        static::saved(fn () => Cache::forget('api.delivery-zones'));
        static::deleted(fn () => Cache::forget('api.delivery-zones'));
    }

    public function couriers(): HasMany
    {
        return $this->hasMany(DeliveryTownshipCourier::class);
    }

    /**
     * Deliverable = active AND at least one courier whose route is open.
     * One definition — the API scope below, the admin badge, and the checkout
     * validation all read this, never a second copy. A township with zero
     * courier rows (Cocokyun) or only suspended ones reads not-serviceable.
     */
    public function isServiceable(): bool
    {
        $this->loadMissing('couriers');

        return $this->is_active
            && $this->couriers->contains(fn (DeliveryTownshipCourier $courier): bool => $courier->is_available);
    }

    public function scopeServiceable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereHas('couriers', fn (Builder $couriers) => $couriers->where('is_available', true));
    }

    /** "Sanchaung (စမ်းချောင်း)" when Burmese is recorded, plain name when not —
     *  matching RoyalX's own selects. Used by the API label and admin options. */
    public function optionLabel(): string
    {
        return $this->name_mm ? "{$this->name} ({$this->name_mm})" : $this->name;
    }

    /** Cheapest recorded cost among open routes — reference only. Null when no
     *  open route has a cost recorded (null means unknown, never 0). */
    public function cheapestAvailableCostMmk(): ?int
    {
        $this->loadMissing('couriers');

        $costs = $this->couriers
            ->filter(fn (DeliveryTownshipCourier $courier): bool => $courier->is_available && $courier->cost_mmk !== null)
            ->pluck('cost_mmk');

        return $costs->isEmpty() ? null : (int) $costs->min();
    }

    /**
     * Display-only best case: fee − cheapest recorded cost. Null (rendered
     * blank, never 0) when no cost is known — the v19 rule. Never enters any
     * stored money figure; the P&L's courier-paid side stays the `delivery`
     * expense category alone.
     */
    public function bestCaseMarginMmk(): ?int
    {
        $cheapest = $this->cheapestAvailableCostMmk();

        return $cheapest === null ? null : $this->fee_mmk - $cheapest;
    }

    /**
     * Courier choices for an order to this township, each labelled with its
     * recorded cost so the decanter picks while looking at the number — used
     * by the order form and the Accept modal, admin-eyes only. Open routes
     * only; when no township is chosen or none is recorded (a DM order to
     * anywhere), every courier is offered plain — recording who carried a
     * parcel must never be blocked by missing rate rows.
     *
     * @return array<string, string>
     */
    public static function courierOptionsFor(?int $townshipId): array
    {
        $rows = $townshipId
            ? DeliveryTownshipCourier::query()
                ->where('delivery_township_id', $townshipId)
                ->where('is_available', true)
                ->get()
            : collect();

        if ($rows->isEmpty()) {
            return collect(\App\Enums\Courier::cases())
                ->mapWithKeys(fn (\App\Enums\Courier $courier) => [$courier->value => $courier->label()])
                ->all();
        }

        return $rows
            ->mapWithKeys(fn (DeliveryTownshipCourier $row) => [
                $row->courier->value => $row->courier->label().' — '.($row->cost_mmk !== null
                    ? 'cost '.\App\Support\Money::kyat($row->cost_mmk)
                    : 'cost unknown'),
            ])
            ->all();
    }

    protected function casts(): array
    {
        return [
            'region' => Region::class,
            'fee_mmk' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}

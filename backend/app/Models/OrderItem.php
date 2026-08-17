<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'fragrance_id', 'fragrance_name_snapshot', 'size_ml', 'unit_price_mmk', 'unit_cost_mmk', 'quantity', 'line_total_mmk', 'line_cost_mmk'])]
class OrderItem extends Model
{
    use BelongsToShop;

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            // Cost snapshot, creating-only — cost behaves exactly like price:
            // written once from the live reference when the item is created
            // (checkout, manual admin entry, or a line added to an old order),
            // never refreshed on a later save. A snapshot that recomputes is a
            // cache; this one prices the pour this order actually gets. find(),
            // not the relation, so an unloaded relation can't trip the dev/test
            // lazy-loading guard.
            if (! $item->exists && $item->unit_cost_mmk === null && $item->fragrance_id !== null) {
                $fragrance = $item->relationLoaded('fragrance')
                    ? $item->fragrance
                    : Fragrance::query()->find($item->fragrance_id);

                $item->unit_cost_mmk = $fragrance?->liquidCostMmk((int) $item->size_ml);
            }

            $item->line_total_mmk = $item->unit_price_mmk * $item->quantity;

            // Null-propagating: a quantity edit re-derives from the STORED unit
            // cost, and an unknown unit cost yields an unknown line cost — never 0,
            // because a zero-cost line is a 100%-margin lie.
            $item->line_cost_mmk = $item->unit_cost_mmk === null
                ? null
                : $item->unit_cost_mmk * $item->quantity;
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fragrance(): BelongsTo
    {
        return $this->belongsTo(Fragrance::class);
    }

    protected function casts(): array
    {
        return [
            'size_ml' => 'integer',
            'unit_price_mmk' => 'integer',
            'unit_cost_mmk' => 'integer',
            'quantity' => 'integer',
            'line_total_mmk' => 'integer',
            'line_cost_mmk' => 'integer',
        ];
    }
}

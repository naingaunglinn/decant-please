<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'product_id', 'product_variant_id', 'fragrance_name_snapshot', 'variant_label_snapshot', 'size_ml', 'unit_price_mmk', 'unit_cost_mmk', 'quantity', 'line_total_mmk', 'line_cost_mmk'])]
class OrderItem extends Model
{
    use BelongsToShop;

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            // Variant snapshot, creating-only, like the name snapshot: which
            // variant this line sold and how it read at the time ("10ml"). A line
            // entered by product + size (the admin form, the decant checkout) is
            // matched to its variant here, so every write path stamps it once.
            if (! $item->exists && $item->product_variant_id === null && $item->product_id !== null && $item->size_ml !== null) {
                $item->product_variant_id = ProductVariant::query()
                    ->where('product_id', $item->product_id)
                    ->where('size_ml', $item->size_ml)
                    ->value('id');
            }

            if (! $item->exists && $item->variant_label_snapshot === null) {
                $item->variant_label_snapshot = $item->product_variant_id !== null
                    ? ProductVariant::query()->find($item->product_variant_id)?->label()
                    : null;
                $item->variant_label_snapshot ??= $item->size_ml !== null ? "{$item->size_ml}ml" : null;
            }

            // Cost snapshot, creating-only — cost behaves exactly like price:
            // written once from the live reference when the item is created
            // (checkout, manual admin entry, or a line added to an old order),
            // never refreshed on a later save. A snapshot that recomputes is a
            // cache; this one prices what this order actually gets. After the
            // variant match above, because the product's stock mode picks the
            // source (step 40): pooled costs its share of the reference purchase,
            // per variant reads the variant's own cost — never one falling back
            // to the other, and unknown stays null. find(), not the relation, so
            // an unloaded relation can't trip the dev/test lazy-loading guard.
            if (! $item->exists && $item->unit_cost_mmk === null && $item->product_id !== null) {
                $item->unit_cost_mmk = self::currentUnitCost(
                    $item->relationLoaded('product') ? $item->product : Product::query()->find($item->product_id),
                    $item->product_variant_id,
                    $item->size_ml,
                );
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

    /**
     * What one unit of this line costs the seller today, by the product's stock
     * mode, or null when unknown. The one cost rule — the line snapshot and the
     * admin order form's pre-fill both call it.
     */
    public static function currentUnitCost(?Product $product, ?int $variantId, ?int $sizeMl): ?int
    {
        if ($product === null) {
            return null;
        }

        if ($product->pooledStock()) {
            return $product->pooledCostMmk((int) $sizeMl);
        }

        $cost = $variantId === null
            ? null
            : ProductVariant::query()->where('product_id', $product->id)->whereKey($variantId)->value('unit_cost_mmk');

        return $cost === null ? null : (int) $cost;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * How this line's variant reads on every admin surface — invoice, schedule,
     * order list, Telegram: the frozen label ("10ml", "M / Blue"), falling back
     * to the size for a line that has none.
     */
    public function variantLabel(): string
    {
        return $this->variant_label_snapshot ?? ($this->size_ml !== null ? "{$this->size_ml}ml" : '—');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
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

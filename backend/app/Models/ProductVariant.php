<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sellable option of a product (step 36; was `DecantPrice`): a price, an
 * in-stock flag and its option values (`{"Size":"10ml"}`). A variant on a placed
 * order is never deleted (order_items restricts it) — it is archived with
 * `is_active`, which hides it from the storefront and checkout.
 */
#[Fillable(['product_id', 'size_ml', 'price_mmk', 'in_stock', 'options', 'measure', 'is_active', 'position'])]
class ProductVariant extends Model
{
    use BelongsToShop;

    /** Mirror the column defaults, so a just-created variant reads them too. */
    protected $attributes = ['is_active' => true, 'position' => 0];

    protected static function booted(): void
    {
        // Decant variants are defined by their size; keep the generic columns in
        // step with it until a template (step 37/38) supplies its own options.
        static::saving(function (self $variant) {
            if ($variant->size_ml !== null && ($variant->options === null || $variant->isDirty('size_ml'))) {
                $variant->options = ['Size' => "{$variant->size_ml}ml"];
                $variant->measure = $variant->size_ml;
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** What an order line shows for this variant: its option values, "10ml" or "M / Blue". */
    public function label(): string
    {
        return implode(' / ', array_values($this->options ?? []));
    }

    protected function casts(): array
    {
        return [
            'size_ml' => 'integer',
            'price_mmk' => 'integer',
            'in_stock' => 'boolean',
            'options' => 'array',
            'measure' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}

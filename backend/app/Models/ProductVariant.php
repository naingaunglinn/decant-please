<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One sellable option of a product (step 36; was `DecantPrice`): a price, an
 * in-stock flag and its option values (`{"Size":"10ml"}`). A variant on a placed
 * order is never deleted (order_items restricts it) — it is archived with
 * `is_active`, which hides it from the storefront and checkout. A per-variant
 * template (step 40) counts its pieces in `stock_qty` and costs one in
 * `unit_cost_mmk`; both are null (untracked, unknown) for a pooled one.
 */
#[Fillable(['product_id', 'size_ml', 'price_mmk', 'in_stock', 'options', 'measure', 'is_active', 'position', 'image_path', 'stock_qty', 'unit_cost_mmk'])]
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

            // A variant with no size is its option values (step 38): trimmed and in
            // the template's option order, so it labels "M / Blue" however it was
            // entered. A key the template doesn't name is kept, after them.
            $product = $variant->size_ml === null && $variant->isDirty('options')
                ? ($variant->relationLoaded('product') ? $variant->product : Product::query()->find($variant->product_id))
                : null;

            if ($product !== null) {
                $given = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $variant->options ?? []);
                $ordered = [];

                foreach ($product->catalogTemplate()->variantOptions() as $name) {
                    if (filled($given[$name] ?? null)) {
                        $ordered[$name] = $given[$name];
                    }
                    unset($given[$name]);
                }

                $variant->options = $ordered + $given;
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

    /** Public URL of this variant's own photo (a photo per colour, step 38), or null. */
    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk(config('filesystems.media_disk'))->url($this->image_path) : null;
    }

    /**
     * Take `$quantity` pieces off this variant, clamped at zero — warn-only, like
     * the pooled draw-down. No-op when untracked. Order::drawDownStock() calls it
     * on a row it has locked.
     */
    public function drawDownStock(int $quantity): bool
    {
        if ($this->stock_qty === null || $quantity <= 0) {
            return false;
        }

        $this->stock_qty = max(0, $this->stock_qty - $quantity);
        $this->save();

        return true;
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
            'stock_qty' => 'integer',
            'unit_cost_mmk' => 'integer',
        ];
    }
}

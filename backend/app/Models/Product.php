<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasSlug;
use App\Support\StockUnit;
use App\Templates\Template;
use App\Templates\Templates;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;

#[Fillable(['brand_id', 'category_id', 'template', 'name', 'slug', 'attributes', 'description', 'image_path', 'is_active', 'is_featured', 'stock_amount', 'stock_unit', 'low_stock_threshold', 'reference_cost_mmk', 'reference_amount'])]
/**
 * A catalog product (step 36; was `Fragrance`). Its sellable options are
 * ProductVariant rows. What it carries beyond the core columns is its template's
 * attributes (step 37), stored in the `attributes` jsonb column — read one with
 * attr(), never `$this->attributes` inside this class (that is Eloquent's own raw
 * array). Stock is counted the way its template says (step 40, Template::stockMode()):
 * pooled in stock_amount, or per variant in product_variants.stock_qty.
 */
class Product extends Model
{
    use BelongsToShop;
    use HasSlug;

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            // A new product takes the shop's default template; a changed one must
            // stay in the shop's group (roadmap decision 3).
            $product->template ??= Templates::shopDefaultKey();

            if ($product->isDirty('template')) {
                Templates::assertAllowedForShop($product->template);
            }

            // No foreign key crosses a shop boundary: the category must be this
            // shop's (the scope makes another shop's id not found).
            if ($product->isDirty('category_id') && $product->category_id !== null
                && ! Category::query()->whereKey($product->category_id)->exists()) {
                throw new InvalidArgumentException('That category does not exist in this shop.');
            }

            $product->stampStockUnit();

            if ($product->search_text === null || $product->isDirty(['name', 'brand_id', 'attributes', 'template'])) {
                $product->refreshSearchText();
            }
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function catalogTemplate(): Template
    {
        return Templates::get($this->template ?? Templates::shopDefaultKey());
    }

    /** One stored attribute value (products.attributes), or null. */
    public function attr(string $key): mixed
    {
        return ($this->getAttribute('attributes') ?? [])[$key] ?? null;
    }

    /** An attribute as a customer reads it: a select's label ("EDP"), else the value. */
    public function attrDisplay(string $key): ?string
    {
        return $this->catalogTemplate()->attribute($key)?->display($this->attr($key));
    }

    /**
     * Rebuild search_text from brand, name and the searchable attributes. The
     * brand name is queried, not lazy-loaded, unless the relation is already here
     * and current (a Brand rename sets it; see Brand::booted()).
     */
    public function refreshSearchText(): void
    {
        // A loaded brand is stale once brand_id changes (Laravel keeps the old one).
        $brandName = $this->relationLoaded('brand') && ! $this->isDirty('brand_id')
            ? $this->brand?->name
            : ($this->brand_id ? Brand::query()->whereKey($this->brand_id)->value('name') : null);

        $this->search_text = $this->catalogTemplate()->searchText($brandName, (string) $this->name, $this->getAttribute('attributes') ?? []);
    }

    /**
     * Every variant, archived ones included — the admin edits all of them. The
     * storefront and checkout read activeVariants().
     */
    public function variants(): HasMany
    {
        // measure breaks position ties (it mirrors size_ml for decant; step 40b
        // weighs by it), so a variant created without a position lists smallest-first.
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('measure')->orderBy('id');
    }

    /** What a customer can see and buy: archived variants never leave the admin. */
    public function activeVariants(): HasMany
    {
        return $this->variants()->where('is_active', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * What the storefront lists and checkout sells (step 38b): an active product
     * whose brand, if it has one, is active too. Brand is optional for a template
     * like clothing, so a brandless product sells; a hidden brand still hides its
     * products. The one place this rule lives — the API, /meta and checkout call it.
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('products.is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereNull('products.brand_id')
                ->orWhereHas('brand', fn (Builder $brand) => $brand->where('is_active', true)));
    }

    /** scopeSellable() for a loaded product. */
    public function isSellable(): bool
    {
        return $this->is_active && ($this->brand_id === null || (bool) $this->brand?->is_active);
    }

    /**
     * Substring search over search_text. Case-folded in PHP on both sides, so the
     * column is never wrapped in LOWER(), and `%` / `_` in the needle are literal.
     */
    public function scopeSearch(Builder $query, string $needle): Builder
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($needle));

        return $query->whereRaw("products.search_text LIKE ? ESCAPE '\\'", ["%{$escaped}%"]);
    }

    public function pooledStock(): bool
    {
        return $this->catalogTemplate()->pooledStock();
    }

    /**
     * A pooled product's numbers are in its template's unit (step 40b): a new one
     * takes it; a switch to a template in another unit is refused while anything
     * is counted in the old one — the stock, the reference purchase, or an order
     * line's frozen amount. Otherwise 500 ml would read as 500 kyatthar, and an
     * accepted order's 10ml would draw 10 kyatthar. A per-variant template keeps
     * the unit, so switching back to the same one loses nothing.
     */
    protected function stampStockUnit(): void
    {
        $template = $this->catalogTemplate();
        $unit = $template->pooledStock() ? $template->measure() : null;

        if ($unit === null || $unit === $this->stock_unit) {
            return;
        }

        if ($this->exists && ($this->stock_amount !== null || $this->reference_cost_mmk !== null
            || $this->reference_amount !== null || $this->hasMeasuredOrderLines())) {
            throw new InvalidArgumentException(
                'This product is counted in '.($this->stock_unit ?? 'another unit').'. Clear its stock and cost first — or, once it has sold, add it again as a new product.'
            );
        }

        $this->stock_unit = $unit;
    }

    private function hasMeasuredOrderLines(): bool
    {
        return OrderItem::query()
            ->where('order_items.product_id', $this->id)
            ->whereNotNull('order_items.measure')
            ->exists();
    }

    /** An amount of this product's pooled stock in words: "30ml", "1 viss 50 kyatthar". */
    public function formatAmount(int $amount): string
    {
        return StockUnit::format($amount, $this->stock_unit ?? $this->catalogTemplate()->measure());
    }

    /**
     * Products at or below their reorder line, each in its own stock mode (the
     * same rule as isLowStock()): the pooled amount, or any selling variant's
     * pieces. Untracked (null) counts never match, nor does a count left over
     * from the other mode after a template switch.
     * Both sides of each comparison are qualified — the variant one runs inside a
     * correlated subquery, where a bare column is ambiguous on Postgres.
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $pooled) => $pooled
                ->whereIn('products.template', Templates::pooledKeys())
                ->whereNotNull('products.stock_amount')
                ->whereColumn('products.stock_amount', '<=', 'products.low_stock_threshold'))
            ->orWhere(fn (Builder $perVariant) => $perVariant
                ->whereNotIn('products.template', Templates::pooledKeys())
                ->whereHas('activeVariants', fn (Builder $variant) => $variant
                    ->whereNotNull('product_variants.stock_qty')
                    ->whereColumn('product_variants.stock_qty', '<=', 'products.low_stock_threshold'))));
    }

    /** Tracking is opt-in: a null count (the product's, or every selling variant's) is not tracked. */
    public function isStockTracked(): bool
    {
        return $this->pooledStock()
            ? $this->stock_amount !== null
            : $this->variants->contains(fn (ProductVariant $variant): bool => $variant->is_active && $variant->stock_qty !== null);
    }

    public function isLowStock(): bool
    {
        return $this->pooledStock()
            ? $this->stock_amount !== null && $this->stock_amount <= $this->low_stock_threshold
            : $this->lowVariants()->isNotEmpty();
    }

    /**
     * A per-variant product's selling variants at or below the reorder line, from
     * the loaded variants (eager-load them for a list).
     *
     * @return Collection<int, ProductVariant>
     */
    public function lowVariants(): Collection
    {
        return $this->variants->filter(fn (ProductVariant $variant): bool => $variant->is_active
            && $variant->stock_qty !== null
            && $variant->stock_qty <= $this->low_stock_threshold)->values();
    }

    /**
     * Topping up the shelf: a bottle or a sack is just more on the running total,
     * in the product's unit. Starts tracking a previously-untracked product.
     */
    public function addStock(int $amount): void
    {
        $this->stock_amount = ($this->stock_amount ?? 0) + max(0, $amount);
        $this->save();
    }

    /**
     * Take `$amount` off the pooled running total, clamped at zero. Warn-only: a
     * shortfall doesn't block — the low-stock panel surfaces it, the seller
     * reorders. No-op when untracked. Returns true if anything was drawn down.
     * Order::drawDownStock() calls it on a row it has locked.
     */
    public function drawDownStock(int $amount): bool
    {
        if ($this->stock_amount === null || $amount <= 0) {
            return false;
        }

        $this->stock_amount = max(0, $this->stock_amount - $amount);
        $this->save();

        return true;
    }

    /**
     * What $amount (in stock_unit) costs from the reference purchase — the bottle
     * or the sack only: vial, bag, label and spillage are deliberately not in this
     * number, and every label that shows it must say so. Null unless the reference
     * pair is set.
     *
     * Pure-integer CEILING division — deliberately the project's second rounding
     * rule, beside PromoCode's floor, because the directions of safety differ:
     * flooring a discount can only make the shop keep more, while flooring a cost
     * would understate cost and flatter every margin figure. Ceiling overstates
     * cost by at most 1 Ks per vial — margin errs conservative.
     */
    public function pooledCostMmk(int $amount): ?int
    {
        if ($this->reference_cost_mmk === null || $this->reference_amount === null
            || $this->reference_amount < 1 || $amount < 1) {
            return null;
        }

        return intdiv($this->reference_cost_mmk * $amount + $this->reference_amount - 1, $this->reference_amount);
    }

    /**
     * Lowest in-stock active variant price, for "From 30,000 Ks" cards. Null when nothing is in stock.
     */
    public function minPrice(): ?int
    {
        $min = $this->activeVariants()->where('in_stock', true)->min('price_mmk');

        return $min === null ? null : (int) $min;
    }

    protected function slugSource(): string
    {
        return trim(($this->brand?->name ?? '').' '.$this->name);
    }

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'stock_amount' => 'integer',
            'low_stock_threshold' => 'integer',
            'reference_cost_mmk' => 'integer',
            'reference_amount' => 'integer',
        ];
    }
}

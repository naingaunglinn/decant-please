<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasSlug;
use App\Templates\Template;
use App\Templates\Templates;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable(['brand_id', 'category_id', 'template', 'name', 'slug', 'attributes', 'description', 'image_path', 'is_active', 'is_featured', 'stock_ml', 'low_stock_threshold_ml', 'bottle_cost_mmk', 'bottle_volume_ml'])]
/**
 * A catalog product (step 36; was `Fragrance`). Its sellable options are
 * ProductVariant rows. What it carries beyond the core columns is its template's
 * attributes (step 37), stored in the `attributes` jsonb column — read one with
 * attr(), never `$this->attributes` inside this class (that is Eloquent's own raw
 * array). The stock and reference-bottle columns stay until step 40 generalizes stock.
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
        // size_ml breaks position ties, so a variant created without a position
        // (all of them today) still lists smallest-first, as decant sizes always have.
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('size_ml')->orderBy('id');
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
     * Substring search over search_text. Case-folded in PHP on both sides, so the
     * column is never wrapped in LOWER(), and `%` / `_` in the needle are literal.
     */
    public function scopeSearch(Builder $query, string $needle): Builder
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($needle));

        return $query->whereRaw("products.search_text LIKE ? ESCAPE '\\'", ["%{$escaped}%"]);
    }

    /** Fragrances the decanter tracks by volume and is at/below the reorder line. */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereNotNull('stock_ml')
            ->whereColumn('stock_ml', '<=', 'low_stock_threshold_ml');
    }

    /** Volume tracking is opt-in — null stock_ml means this fragrance isn't tracked. */
    public function isStockTracked(): bool
    {
        return $this->stock_ml !== null;
    }

    public function isLowStock(): bool
    {
        return $this->isStockTracked() && $this->stock_ml <= $this->low_stock_threshold_ml;
    }

    /**
     * Topping up the shelf: adding a bottle is just more millilitres on the
     * running total. Starts tracking a previously-untracked fragrance.
     */
    public function addBottle(int $ml): void
    {
        $this->stock_ml = ($this->stock_ml ?? 0) + max(0, $ml);
        $this->save();
    }

    /**
     * Pour `$ml` off the running total, clamped at zero. Warn-only: a shortfall
     * doesn't block — the low-stock panel surfaces it, the decanter reorders.
     * No-op for an untracked fragrance. Returns true if anything was drawn down.
     */
    public function drawDownStock(int $ml): bool
    {
        if (! $this->isStockTracked() || $ml <= 0) {
            return false;
        }

        $this->stock_ml = max(0, $this->stock_ml - $ml);
        $this->save();

        return true;
    }

    /**
     * What $sizeMl of juice costs from the reference bottle — LIQUID ONLY: vial,
     * label, and spillage are deliberately not in this number, and every label
     * that shows it must say so. Null unless the reference pair is set.
     *
     * Pure-integer CEILING division — deliberately the project's second rounding
     * rule, beside PromoCode's floor, because the directions of safety differ:
     * flooring a discount can only make the shop keep more, while flooring a cost
     * would understate cost and flatter every margin figure. Ceiling overstates
     * cost by at most 1 Ks per vial — margin errs conservative.
     */
    public function liquidCostMmk(int $sizeMl): ?int
    {
        if ($this->bottle_cost_mmk === null || $this->bottle_volume_ml === null
            || $this->bottle_volume_ml < 1 || $sizeMl < 1) {
            return null;
        }

        return intdiv($this->bottle_cost_mmk * $sizeMl + $this->bottle_volume_ml - 1, $this->bottle_volume_ml);
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
            'stock_ml' => 'integer',
            'low_stock_threshold_ml' => 'integer',
            'bottle_cost_mmk' => 'integer',
            'bottle_volume_ml' => 'integer',
        ];
    }
}

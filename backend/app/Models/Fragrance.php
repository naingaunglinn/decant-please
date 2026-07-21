<?php

namespace App\Models;

use App\Enums\Concentration;
use App\Enums\Gender;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['brand_id', 'name', 'slug', 'concentration', 'gender', 'notes', 'vibes', 'performance', 'description', 'image_path', 'is_active', 'is_featured', 'stock_ml', 'low_stock_threshold_ml'])]
class Fragrance extends Model
{
    use HasSlug;

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function decantPrices(): HasMany
    {
        return $this->hasMany(DecantPrice::class)->orderBy('size_ml');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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
     * Lowest in-stock decant price, for "From 30,000 Ks" cards. Null when nothing is in stock.
     */
    public function minPrice(): ?int
    {
        $min = $this->decantPrices()->where('in_stock', true)->min('price_mmk');

        return $min === null ? null : (int) $min;
    }

    protected function slugSource(): string
    {
        return trim(($this->brand?->name ?? '').' '.$this->name);
    }

    protected function casts(): array
    {
        return [
            'concentration' => Concentration::class,
            'gender' => Gender::class,
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'stock_ml' => 'integer',
            'low_stock_threshold_ml' => 'integer',
        ];
    }
}

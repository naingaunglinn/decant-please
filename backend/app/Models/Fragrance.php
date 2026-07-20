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
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['brand_id', 'name', 'slug', 'concentration', 'gender', 'notes', 'vibes', 'performance', 'description', 'image_path', 'is_active', 'is_featured'])]
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

    public function bottles(): HasMany
    {
        return $this->hasMany(Bottle::class);
    }

    /** The bottle currently being poured from — Bottle::logFor keeps this unique. */
    public function activeBottle(): HasOne
    {
        return $this->hasOne(Bottle::class)->where('is_active', true);
    }

    /**
     * Recompute every size's in_stock from what's physically left in the active
     * bottle. A fragrance with *no* active bottle is left completely alone —
     * "not tracked yet" is a different state from "0ml remaining", and the manual
     * in_stock flags keep working exactly as they did before bottles existed.
     */
    public function syncStockFromBottle(): void
    {
        $bottle = $this->activeBottle()->first();

        if (! $bottle) {
            return;
        }

        $this->decantPrices()->get()->each(fn (DecantPrice $price) => $price->update([
            'in_stock' => $bottle->remaining_ml >= $price->size_ml,
        ]));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\BrandType;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'type', 'logo_path', 'is_active'])]
class Brand extends Model
{
    use BelongsToShop;
    use HasSlug;

    protected static function booted(): void
    {
        // A product's search_text carries its brand's name, and the product's own
        // saving hook never sees a brand rename — so the rename rebuilds them here.
        static::saved(function (Brand $brand): void {
            if (! $brand->wasChanged('name')) {
                return;
            }

            $brand->products()->get()->each(function (Product $product) use ($brand): void {
                $product->setRelation('brand', $brand);
                $product->refreshSearchText();
                $product->save();
            });
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected function casts(): array
    {
        return [
            'type' => BrandType::class,
            'is_active' => 'boolean',
        ];
    }
}

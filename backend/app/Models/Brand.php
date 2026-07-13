<?php

namespace App\Models;

use App\Enums\BrandType;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'type', 'logo_path', 'is_active'])]
class Brand extends Model
{
    use HasSlug;

    public function fragrances(): HasMany
    {
        return $this->hasMany(Fragrance::class);
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

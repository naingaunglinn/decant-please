<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The tenant root. Deliberately NOT tenant-owned itself — it carries no shop_id and
 * uses no BelongsToShop trait; it is what everything else scopes to. Step A keeps it
 * minimal (slug/name/is_active); contact, Telegram, and theming columns wait for
 * Step 25 (design-doc §7).
 */
#[Fillable(['slug', 'name', 'is_active'])]
class Shop extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

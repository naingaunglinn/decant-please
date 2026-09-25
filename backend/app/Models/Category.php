<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shop's own product grouping — menu sections, "tops / dresses" (step 37).
 * Deleting one leaves its products uncategorised (products.category_id is null
 * on delete). No admin screen yet: menu categories arrive with group 3.
 */
#[Fillable(['name', 'position'])]
class Category extends Model
{
    use BelongsToShop;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}

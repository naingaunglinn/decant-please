<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['fragrance_id', 'size_ml', 'price_mmk', 'in_stock'])]
class DecantPrice extends Model
{
    use BelongsToShop;

    public function fragrance(): BelongsTo
    {
        return $this->belongsTo(Fragrance::class);
    }

    protected function casts(): array
    {
        return [
            'size_ml' => 'integer',
            'price_mmk' => 'integer',
            'in_stock' => 'boolean',
        ];
    }
}

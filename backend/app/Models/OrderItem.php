<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'fragrance_id', 'fragrance_name_snapshot', 'size_ml', 'unit_price_mmk', 'quantity', 'line_total_mmk'])]
class OrderItem extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $item->line_total_mmk = $item->unit_price_mmk * $item->quantity;
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fragrance(): BelongsTo
    {
        return $this->belongsTo(Fragrance::class);
    }

    protected function casts(): array
    {
        return [
            'size_ml' => 'integer',
            'unit_price_mmk' => 'integer',
            'quantity' => 'integer',
            'line_total_mmk' => 'integer',
        ];
    }
}

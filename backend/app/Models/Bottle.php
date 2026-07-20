<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['fragrance_id', 'total_ml', 'remaining_ml', 'opened_at', 'is_active'])]
class Bottle extends Model
{
    public function fragrance(): BelongsTo
    {
        return $this->belongsTo(Fragrance::class);
    }

    /**
     * The one way a bottle enters the system. Deactivates the fragrance's
     * current active bottle (if any) so "one active bottle per fragrance"
     * holds, starts the new one full, and recomputes every size's in_stock
     * from it. Stock never carries over — a new bottle's remaining_ml is its
     * own total_ml, not the old bottle's leftovers added on.
     */
    public static function logFor(Fragrance $fragrance, int $totalMl, string $openedAt): self
    {
        return DB::transaction(function () use ($fragrance, $totalMl, $openedAt) {
            $fragrance->bottles()->where('is_active', true)->update(['is_active' => false]);

            $bottle = $fragrance->bottles()->create([
                'total_ml' => $totalMl,
                'remaining_ml' => $totalMl,
                'opened_at' => $openedAt,
                'is_active' => true,
            ]);

            $fragrance->syncStockFromBottle();

            return $bottle;
        });
    }

    protected function casts(): array
    {
        return [
            'total_ml' => 'integer',
            'remaining_ml' => 'integer',
            'opened_at' => 'date',
            'is_active' => 'boolean',
        ];
    }
}

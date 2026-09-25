<?php

namespace App\Models;

use App\Enums\DesignSource;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One version of a shop's storefront design (step 46). Rows are append-only:
 * App\Design\Designs is the only writer, validates the config before insert,
 * and never updates a row — undo is publishing an older one
 * (shop_settings.published_design_id).
 *
 * `config` is jsonb: Postgres doesn't keep an object's key order, only a list's.
 * Order that means something (sections, items) is always a list.
 */
#[Fillable(['config', 'source', 'prompt', 'input_tokens', 'output_tokens', 'created_by'])]
class ShopDesign extends Model
{
    use BelongsToShop;

    protected static function booted(): void
    {
        // Append-only by construction, like an order's snapshots: a published row
        // that changed under the shop would move its live storefront (and undo).
        static::updating(function (): never {
            throw new LogicException('A shop design is never updated. Create a new one with App\\Design\\Designs::create().');
        });
    }

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'source' => DesignSource::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

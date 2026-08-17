<?php

namespace App\Models\Concerns;

use App\Exceptions\TenantNotSetException;
use App\Models\Shop;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model tenant-owned. Every query gets a global scope that:
 *   - applies nothing when TenantContext is bypassing (withoutTenancy);
 *   - THROWS TenantNotSetException when no shop is set (ADR-002 Option C — a
 *     forgotten filter fails loudly, it never silently returns all shops);
 *   - otherwise filters by the *qualified* shop_id.
 *
 * The column is qualified (`orders.shop_id`, never a bare `shop_id`) because joins
 * do not apply the joined model's scopes: TopFragrances joins orders+order_items,
 * and a bare `shop_id` there is "ambiguous" on Postgres while SQLite tolerates it
 * (findings Q5). A creating hook fills shop_id from the context so writes — including
 * ShopSetting::current()'s firstOrCreate([]) — carry the tenant without callers
 * remembering to.
 */
trait BelongsToShop
{
    protected static function bootBelongsToShop(): void
    {
        static::addGlobalScope('shop', function (Builder $builder): void {
            $context = app(TenantContext::class);

            if ($context->bypassing()) {
                return;
            }

            if (! $context->has()) {
                throw new TenantNotSetException(static::class);
            }

            $builder->where($builder->getModel()->qualifyColumn('shop_id'), $context->id());
        });

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if ($model->getAttribute('shop_id') === null && $context->has()) {
                $model->setAttribute('shop_id', $context->id());
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}

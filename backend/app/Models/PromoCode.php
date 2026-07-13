<?php

namespace App\Models;

use App\Enums\PromoType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['code', 'type', 'value', 'max_discount_mmk', 'min_order_mmk', 'usage_limit', 'times_used', 'starts_at', 'expires_at', 'is_active'])]
class PromoCode extends Model
{
    /** Codes are stored uppercase so matching is case-insensitive by construction. */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => Str::upper(trim($value)),
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', today()))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()));
    }

    /**
     * The one evaluation both the preview endpoint and checkout go through, so
     * they can never disagree. Checkout passes $lock = true inside its
     * transaction so a limited-use code can't be double-spent in a race.
     *
     * @return array{valid: bool, discount_mmk: int, message: ?string, promo: ?self}
     */
    public static function evaluate(string $code, int $subtotalMmk, bool $lock = false): array
    {
        $promo = self::query()
            ->active()
            ->where('code', Str::upper(trim($code)))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->first();

        if (! $promo) {
            return ['valid' => false, 'discount_mmk' => 0, 'message' => "We couldn't find that code.", 'promo' => null];
        }

        if ($promo->usage_limit !== null && $promo->times_used >= $promo->usage_limit) {
            return ['valid' => false, 'discount_mmk' => 0, 'message' => 'That code has reached its usage limit.', 'promo' => $promo];
        }

        if ($promo->min_order_mmk && $subtotalMmk < $promo->min_order_mmk) {
            return ['valid' => false, 'discount_mmk' => 0, 'message' => 'This code needs an order of at least '.Money::kyat($promo->min_order_mmk).'.', 'promo' => $promo];
        }

        $discount = $promo->type === PromoType::Percent
            ? (int) floor($subtotalMmk * $promo->value / 100)
            : $promo->value;

        if ($promo->type === PromoType::Percent && $promo->max_discount_mmk) {
            $discount = min($discount, $promo->max_discount_mmk);
        }

        return ['valid' => true, 'discount_mmk' => min($discount, $subtotalMmk), 'message' => null, 'promo' => $promo];
    }

    protected function casts(): array
    {
        return [
            'type' => PromoType::class,
            'value' => 'integer',
            'max_discount_mmk' => 'integer',
            'min_order_mmk' => 'integer',
            'usage_limit' => 'integer',
            'times_used' => 'integer',
            'starts_at' => 'date',
            'expires_at' => 'date',
            'is_active' => 'boolean',
        ];
    }
}

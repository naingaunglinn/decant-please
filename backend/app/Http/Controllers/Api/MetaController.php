<?php

namespace App\Http\Controllers\Api;

use App\Enums\BrandType;
use App\Enums\Concentration;
use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Models\DecantPrice;
use App\Models\ShopSetting;
use App\Support\ShopConfig;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // Per-shop key: /meta carries the DB-backed payment block, so a global key
        // would serve one shop's KBZPay/Wave numbers to another (findings Q5/A5).
        $key = 'api.meta.'.app(TenantContext::class)->slug();

        return response()->json(Cache::remember($key, 600, function (): array {
            $available = DecantPrice::query()
                ->where('in_stock', true)
                ->whereHas('fragrance', fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->whereHas('brand', fn (Builder $brand) => $brand->where('is_active', true)));

            $min = $available->clone()->min('price_mmk');
            $max = $available->clone()->max('price_mmk');

            return [
                'brand_types' => $this->options(BrandType::cases()),
                'genders' => $this->options(Gender::cases()),
                'concentrations' => $this->options(Concentration::cases()),
                // ->all(): cache a plain array — a Collection object doesn't survive
                // the cache store's hardened unserialize (comes back as __PHP_Incomplete_Class)
                'sizes' => $available->clone()->distinct()->orderBy('size_ml')->pluck('size_ml')->all(),
                'price' => [
                    'min' => $min !== null ? (int) $min : null,
                    'max' => $max !== null ? (int) $max : null,
                ],
                'sorts' => ['newest', 'price_asc', 'price_desc', 'name'],
                'social' => [
                    // Per-shop since step 33 — shop row → env default → null (ShopConfig).
                    'tiktok_url' => ShopConfig::get('social.tiktok'),
                    'facebook_url' => ShopConfig::get('social.facebook'),
                ],
                'payment' => self::payment(),
            ];
        }));
    }

    /**
     * @param  array<BrandType|Gender|Concentration>  $cases
     * @return array<array{value: string, label: string}>
     */
    protected function options(array $cases): array
    {
        return array_map(fn ($case) => ['value' => $case->value, 'label' => $case->label()], $cases);
    }

    /**
     * Offline payment details for the checkout / receipt. Only configured fields
     * are exposed; the whole block is null when nothing is set, so the storefront
     * can hide payment instructions entirely for a decanter who hasn't added any.
     *
     * @return array<string, string>|null
     */
    protected static function payment(): ?array
    {
        // Shop settings row → env default → off, through the one resolver
        // (step 33). Behaviour is unchanged from the previous inline `?:`: the
        // shop's admin-managed value wins, env is the platform fallback so an
        // existing PAYMENT_* deployment keeps working. qr_url stays derived here
        // — it is a stored path turned into a URL (qrUrl()), not a raw setting,
        // with the env URL as its fallback exactly as before.
        $qrUrl = ShopSetting::current()->qrUrl() ?: config('app.payment.qr_url');

        $fields = array_filter([
            'kbzpay_name' => ShopConfig::get('payment.kbzpay_name'),
            'kbzpay_number' => ShopConfig::get('payment.kbzpay_number'),
            'wave_name' => ShopConfig::get('payment.wave_name'),
            'wave_number' => ShopConfig::get('payment.wave_number'),
            'qr_url' => $qrUrl ?: null,
            'instructions' => ShopConfig::get('payment.instructions'),
        ], fn ($value) => filled($value));

        return $fields === [] ? null : $fields;
    }
}

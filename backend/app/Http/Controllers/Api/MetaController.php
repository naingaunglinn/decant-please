<?php

namespace App\Http\Controllers\Api;

use App\Enums\BrandType;
use App\Enums\Concentration;
use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Models\DecantPrice;
use App\Models\ShopSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(Cache::remember('api.meta', 600, function (): array {
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
                    'tiktok_url' => config('app.social.tiktok') ?: null,
                    'facebook_url' => config('app.social.facebook') ?: null,
                ],
                'payment' => self::payment(),
            ];
        }));
    }

    /**
     * @param  array<\App\Enums\BrandType|\App\Enums\Gender|\App\Enums\Concentration>  $cases
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
        // The decanter's admin-managed settings take precedence; env is the
        // fallback so an existing PAYMENT_* deployment keeps working unchanged.
        $settings = ShopSetting::current();

        $fields = array_filter([
            'kbzpay_name' => $settings->kbzpay_name ?: config('app.payment.kbzpay_name'),
            'kbzpay_number' => $settings->kbzpay_number ?: config('app.payment.kbzpay_number'),
            'wave_name' => $settings->wave_name ?: config('app.payment.wave_name'),
            'wave_number' => $settings->wave_number ?: config('app.payment.wave_number'),
            'qr_url' => $settings->qrUrl() ?: config('app.payment.qr_url'),
            'instructions' => $settings->payment_instructions ?: config('app.payment.instructions'),
        ], fn ($value) => filled($value));

        return $fields === [] ? null : $fields;
    }
}

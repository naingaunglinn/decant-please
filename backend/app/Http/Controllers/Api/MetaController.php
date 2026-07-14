<?php

namespace App\Http\Controllers\Api;

use App\Enums\BrandType;
use App\Enums\Concentration;
use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Models\DecantPrice;
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
}

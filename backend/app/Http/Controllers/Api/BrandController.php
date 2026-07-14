<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class BrandController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // Cache the resolved array, not Eloquent models — objects don't survive
        // the cache store's hardened unserialize (come back as __PHP_Incomplete_Class).
        $brands = Cache::remember('api.brands', 600, fn (): array => BrandResource::collection(
            Brand::query()
                ->active()
                ->withCount(['fragrances' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('name')
                ->get()
        )->resolve());

        return response()->json(['data' => $brands]);
    }
}

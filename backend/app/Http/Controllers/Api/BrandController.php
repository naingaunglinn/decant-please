<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class BrandController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        $brands = Cache::remember('api.brands', 600, fn () => Brand::query()
            ->active()
            ->withCount(['fragrances' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->get());

        return BrandResource::collection($brands);
    }
}

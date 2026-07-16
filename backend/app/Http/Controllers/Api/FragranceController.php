<?php

namespace App\Http\Controllers\Api;

use App\Enums\BrandType;
use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Http\Resources\FragranceResource;
use App\Models\Fragrance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class FragranceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:255'], // comma-separated brand slugs
            'type' => ['nullable', Rule::enum(BrandType::class)],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'size' => ['nullable', 'integer', 'min:1'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'featured' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc', 'name'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $this->baseQuery()
            // whereLike, not where(…, 'like', …): a bare LIKE is case-insensitive on
            // MySQL but case-sensitive on Postgres, so searching "creed" would stop
            // matching "Creed". whereLike defaults to caseSensitive: false and lets
            // each grammar say what it means — ilike on Postgres, like everywhere else.
            ->when($filters['q'] ?? null, fn (Builder $query, string $q) => $query->where(fn (Builder $sub) => $sub
                ->whereLike('name', "%{$q}%")
                ->orWhereHas('brand', fn (Builder $brand) => $brand->whereLike('name', "%{$q}%"))))
            ->when($filters['notes'] ?? null, fn (Builder $query, string $notes) => $query->whereLike('notes', "%{$notes}%"))
            ->when($filters['brand'] ?? null, fn (Builder $query, string $slugs) => $query
                ->whereHas('brand', fn (Builder $brand) => $brand->whereIn('slug', explode(',', $slugs))))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query
                ->whereHas('brand', fn (Builder $brand) => $brand->where('type', $type)))
            ->when($filters['gender'] ?? null, fn (Builder $query, string $gender) => $query->where('gender', $gender))
            ->when($filters['size'] ?? null, fn (Builder $query, int $size) => $query
                ->whereHas('decantPrices', fn (Builder $price) => $price->where('size_ml', $size)->where('in_stock', true)))
            ->when(
                isset($filters['min_price']) || isset($filters['max_price']),
                fn (Builder $query) => $query->whereHas('decantPrices', fn (Builder $price) => $price
                    ->where('in_stock', true)
                    ->when($filters['min_price'] ?? null, fn (Builder $q, int $min) => $q->where('price_mmk', '>=', $min))
                    ->when($filters['max_price'] ?? null, fn (Builder $q, int $max) => $q->where('price_mmk', '<=', $max)))
            )
            ->when($filters['featured'] ?? null, fn (Builder $query) => $query->where('is_featured', true));

        // NULLS LAST rather than the `min_price IS NULL` prefix that did the same job:
        // min_price is a withMin() select alias, and Postgres resolves an alias in
        // ORDER BY only as a bare name, never inside an expression — the old form
        // raised "column min_price does not exist". Out-of-stock rows still sort last
        // either way; Postgres would otherwise put them first on DESC.
        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderByRaw('min_price ASC NULLS LAST')->orderBy('name'),
            'price_desc' => $query->orderByRaw('min_price DESC NULLS LAST')->orderBy('name'),
            'name' => $query->orderBy('name'),
            default => $query->latest()->orderByDesc('id'),
        };

        return FragranceResource::collection(
            $query->paginate($filters['per_page'] ?? 12)->withQueryString()
        );
    }

    public function show(string $slug): FragranceResource
    {
        $fragrance = $this->baseQuery()->where('slug', $slug)->first();

        abort_if($fragrance === null, 404, 'Fragrance not found.');

        return FragranceResource::make($fragrance);
    }

    protected function baseQuery(): Builder
    {
        return Fragrance::query()
            ->active()
            ->whereHas('brand', fn (Builder $brand) => $brand->where('is_active', true))
            ->with(['brand', 'decantPrices'])
            ->withMin(['decantPrices as min_price' => fn ($query) => $query->where('in_stock', true)], 'price_mmk');
    }
}

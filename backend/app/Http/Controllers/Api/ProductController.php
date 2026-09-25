<?php

namespace App\Http\Controllers\Api;

use App\Enums\BrandType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Templates\Attribute;
use App\Templates\Templates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // The shop's template decides which attributes filter (step 37): ?gender=
        // for decant. A select matches its stored value; a text attribute is a
        // substring of search_text, never a LIKE into jsonb.
        $template = Templates::forShop();
        $attributeFilters = $template->filterable();
        // Variant options filter too (step 38): ?option[Size]=M&option[Color]=Blue.
        // Only the template's option names are accepted — each becomes a JSON path.
        $optionNames = $template->measure() === null ? $template->variantOptions() : [];

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:255'], // comma-separated brand slugs
            'type' => ['nullable', Rule::enum(BrandType::class)],
            'size' => ['nullable', 'integer', 'min:1'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'featured' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc', 'name'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            ...($optionNames === [] ? [] : [
                'option' => ['nullable', 'array:'.implode(',', $optionNames)],
                'option.*' => ['nullable', 'string', 'max:50'],
            ]),
            ...collect($attributeFilters)->mapWithKeys(fn (Attribute $attribute): array => [
                $attribute->key => $attribute->type === Attribute::SELECT
                    ? ['nullable', Rule::in(array_keys($attribute->options))]
                    : ['nullable', 'string', 'max:100'],
            ])->all(),
        ]);

        $query = $this->baseQuery()
            ->when($filters['q'] ?? null, fn (Builder $query, string $q) => $query->search($q));

        foreach ($attributeFilters as $attribute) {
            $query->when($filters[$attribute->key] ?? null, fn (Builder $query, string $value) => $attribute->type === Attribute::SELECT
                ? $query->where("products.attributes->{$attribute->key}", $value)
                : $query->search($value));
        }

        $query
            ->when($filters['brand'] ?? null, fn (Builder $query, string $slugs) => $query
                ->whereHas('brand', fn (Builder $brand) => $brand->whereIn('slug', explode(',', $slugs))))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query
                ->whereHas('brand', fn (Builder $brand) => $brand->where('type', $type)))
            ->when($filters['size'] ?? null, fn (Builder $query, int $size) => $query
                ->whereHas('activeVariants', fn (Builder $price) => $price->where('size_ml', $size)->where('in_stock', true)))
            ->when(
                isset($filters['min_price']) || isset($filters['max_price']),
                fn (Builder $query) => $query->whereHas('activeVariants', fn (Builder $price) => $price
                    ->where('in_stock', true)
                    ->when($filters['min_price'] ?? null, fn (Builder $q, int $min) => $q->where('price_mmk', '>=', $min))
                    ->when($filters['max_price'] ?? null, fn (Builder $q, int $max) => $q->where('price_mmk', '<=', $max)))
            )
            ->when($filters['featured'] ?? null, fn (Builder $query) => $query->where('is_featured', true))
            // One in-stock variant must match every picked option (M *and* Blue),
            // not M on one variant and Blue on another. Exact JSON-path equality,
            // like the select attributes — never a LIKE into jsonb.
            ->when(array_filter($filters['option'] ?? [], fn ($value): bool => filled($value)), fn (Builder $query, array $picked) => $query
                ->whereHas('activeVariants', function (Builder $variant) use ($picked): void {
                    $variant->where('in_stock', true);

                    foreach ($picked as $name => $value) {
                        $variant->where("product_variants.options->{$name}", trim($value)); // stored trimmed
                    }
                }));

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

        return ProductResource::collection(
            $query->paginate($filters['per_page'] ?? 12)->withQueryString()
        );
    }

    public function show(string $slug): ProductResource
    {
        $product = $this->baseQuery()->where('slug', $slug)->first();

        abort_if($product === null, 404, 'Fragrance not found.');

        return ProductResource::make($product);
    }

    protected function baseQuery(): Builder
    {
        return Product::query()
            ->sellable()
            ->with(['brand', 'activeVariants'])
            ->withMin(['activeVariants as min_price' => fn ($query) => $query->where('in_stock', true)], 'price_mmk');
    }
}

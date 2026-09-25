<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Support\Money;
use App\Templates\Attribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // set by the controllers' constrained withMin — null when nothing is in stock
        $minPrice = $this->min_price !== null ? (int) $this->min_price : null;
        $template = $this->catalogTemplate();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            // null for a brandless product — brand is optional for some templates (step 38b)
            'brand' => $this->whenLoaded('brand', fn ($brand) => BrandResource::make($brand)),
            'template' => $template->key(),
            // The template's attributes in display order, empty ones left out (step 37).
            'attributes' => collect($template->attributes())
                ->filter(fn (Attribute $attribute): bool => filled($this->attr($attribute->key)))
                ->map(fn (Attribute $attribute): array => [
                    'key' => $attribute->key,
                    'label' => $attribute->label,
                    'value' => $this->attr($attribute->key),
                    'display' => $attribute->display($this->attr($attribute->key)),
                    'show' => $attribute->show($template),
                ])->values()->all(),
            // The flat perfume keys the pre-37 storefront reads, now from attributes.
            // They go with the other deploy aliases after go-live (RUN-QUEUE row 32).
            'concentration' => $this->attr('concentration'),
            'concentration_label' => $this->attrDisplay('concentration'),
            'gender' => $this->attr('gender'),
            'gender_label' => $this->attrDisplay('gender'),
            'notes' => $this->attr('notes'),
            'vibes' => $this->attr('vibes'),
            'performance' => $this->attr('performance'),
            'description' => $this->description,
            'image_url' => $this->image_path ? Storage::disk(config('filesystems.media_disk'))->url($this->image_path) : null,
            'is_featured' => $this->is_featured,
            'min_price_mmk' => $minPrice,
            'min_price_formatted' => $minPrice !== null ? Money::kyat($minPrice) : null,
            // Still keyed `prices` (step 36b): the pre-36b storefront reads this key
            // through the /fragrances alias, so the shape stays additive until go-live.
            'prices' => ProductVariantResource::collection($this->whenLoaded('activeVariants')),
        ];
    }
}

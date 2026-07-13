<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\Fragrance */
class FragranceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // set by the controllers' constrained withMin — null when nothing is in stock
        $minPrice = $this->min_price !== null ? (int) $this->min_price : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'brand' => BrandResource::make($this->whenLoaded('brand')),
            'concentration' => $this->concentration->value,
            'concentration_label' => $this->concentration->label(),
            'gender' => $this->gender->value,
            'gender_label' => $this->gender->label(),
            'notes' => $this->notes,
            'vibes' => $this->vibes,
            'performance' => $this->performance,
            'description' => $this->description,
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'is_featured' => $this->is_featured,
            'min_price_mmk' => $minPrice,
            'min_price_formatted' => $minPrice !== null ? Money::kyat($minPrice) : null,
            'prices' => DecantPriceResource::collection($this->whenLoaded('decantPrices')),
        ];
    }
}

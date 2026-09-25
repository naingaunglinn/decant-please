<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // what checkout sends back as items[].variant_id (step 36b)
            'id' => $this->id,
            'label' => $this->label(),
            'size_ml' => $this->size_ml,
            'price_mmk' => $this->price_mmk,
            'price_formatted' => Money::kyat($this->price_mmk),
            'in_stock' => $this->in_stock,
        ];
    }
}

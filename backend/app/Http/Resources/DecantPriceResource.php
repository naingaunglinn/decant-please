<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DecantPrice */
class DecantPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'size_ml' => $this->size_ml,
            'price_mmk' => $this->price_mmk,
            'price_formatted' => Money::kyat($this->price_mmk),
            'in_stock' => $this->in_stock,
        ];
    }
}

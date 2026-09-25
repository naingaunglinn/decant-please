<?php

namespace App\Templates;

use App\Enums\OrderStatus;

/**
 * Clothing — the second template (step 38; roadmap group 1), the one that proves
 * the generic catalog. A variant is a Size + Color pair ({"Size":"M","Color":"Blue"})
 * with its own price and, optionally, its own photo — the photo per colour. No ml,
 * so no ml stock or cost (per-variant stock is step 40).
 */
class ClothingTemplate extends Template
{
    public function key(): string
    {
        return 'clothing';
    }

    public function name(): string
    {
        return 'Clothing';
    }

    public function group(): int
    {
        return 1;
    }

    public function attributes(): array
    {
        return [
            Attribute::select('material', 'Material', [
                'cotton' => 'Cotton',
                'linen' => 'Linen',
                'silk' => 'Silk',
                'denim' => 'Denim',
                'polyester' => 'Polyester',
                'wool' => 'Wool',
                'knit' => 'Knit',
                'blend' => 'Blend',
                'other' => 'Other',
            ], filterable: true),
            Attribute::select('gender', 'For', [
                'women' => 'Women',
                'men' => 'Men',
                'unisex' => 'Unisex',
                'kids' => 'Kids',
            ], filterable: true),
        ];
    }

    public function variantOptions(): array
    {
        return ['Size', 'Color'];
    }

    public function variantPhotos(): bool
    {
        return true;
    }

    public function variantsHeading(): string
    {
        return 'Sizes, colours & prices';
    }

    public function productNouns(): array
    {
        return ['item', 'items'];
    }

    public function statusLabels(): array
    {
        // Read by step 39. "Decanted" means nothing to a clothes seller; the
        // order is packed and ready instead.
        $labels = array_combine(
            array_map(fn (OrderStatus $status): string => $status->value, OrderStatus::cases()),
            array_map(fn (OrderStatus $status): string => $status->label(), OrderStatus::cases()),
        );
        $labels[OrderStatus::Decanted->value] = 'Packed';

        return $labels;
    }

    public function defaultModules(): array
    {
        // Read by step 41. No ml stock, cost or decant schedule.
        return ['delivery_zones', 'promo_codes', 'expenses'];
    }
}

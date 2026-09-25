<?php

namespace App\Templates;

use App\Enums\Concentration;
use App\Enums\Gender;

/**
 * Perfume decants — the first template (roadmap group 1). Its attributes are the
 * five perfume columns step 37 moved into products.attributes, with the same
 * values, labels and required-ness they had as columns.
 */
class DecantTemplate extends Template
{
    public function key(): string
    {
        return 'decant';
    }

    public function name(): string
    {
        return 'Perfume decants';
    }

    public function group(): int
    {
        return 1;
    }

    public function attributes(): array
    {
        return [
            Attribute::select('concentration', 'Concentration', self::options(Concentration::cases()), required: true),
            Attribute::select('gender', 'Gender', self::options(Gender::cases()), required: true, filterable: true),
            Attribute::text('notes', 'Scent notes', filterable: true, searchable: true, long: true, list: true,
                help: 'Comma separated — e.g. Citrus, Musk, Amber, Orange, Grapefruit'),
            Attribute::text('vibes', 'Vibes', long: true, list: true, help: 'e.g. Modern, Clean, Alluring, Classy'),
            Attribute::text('performance', 'Performance', help: 'e.g. Around 6-8 Hours'),
        ];
    }

    public function headline(): ?string
    {
        return 'concentration';
    }

    public function variantOptions(): array
    {
        return ['Size'];
    }

    public function measure(): ?string
    {
        return 'ml';
    }

    public function brandTypes(): bool
    {
        return true;
    }

    public function variantsHeading(): string
    {
        return 'Decant prices';
    }

    public function productNouns(): array
    {
        return ['fragrance', 'fragrances'];
    }

    public function preparedLabel(): string
    {
        return 'Decanted';
    }

    public function prepDateLabel(): string
    {
        return 'Decant date';
    }

    public function defaultModules(): array
    {
        return ['delivery_zones', 'stock', 'cost', 'production_schedule', 'promo_codes', 'expenses'];
    }

    /**
     * @param  array<Concentration|Gender>  $cases
     * @return array<string, string>
     */
    private static function options(array $cases): array
    {
        return array_combine(
            array_map(fn ($case): string => $case->value, $cases),
            array_map(fn ($case): string => $case->label(), $cases),
        );
    }
}

<?php

namespace App\Enums;

/** Where a shop_designs row came from (step 46). Stored in shop_designs.source — never rename a value. */
enum DesignSource: string
{
    case Preset = 'preset';
    case Manual = 'manual';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Preset => 'Preset',
            self::Manual => 'Edited · ပြင်ထားတာ',
            self::Ai => 'AI',
        };
    }
}

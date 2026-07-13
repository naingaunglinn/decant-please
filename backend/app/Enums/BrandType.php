<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum BrandType: string implements HasLabel
{
    case Designer = 'designer';
    case Niche = 'niche';

    public function getLabel(): string|Htmlable|null
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Designer => 'Designer',
            self::Niche => 'Niche',
        };
    }
}

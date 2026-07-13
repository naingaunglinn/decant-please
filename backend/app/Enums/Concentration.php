<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum Concentration: string implements HasLabel
{
    case Edt = 'edt';
    case Edp = 'edp';
    case Parfum = 'parfum';
    case Cologne = 'cologne';
    case Extrait = 'extrait';
    case Other = 'other';

    public function getLabel(): string|Htmlable|null
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Edt => 'EDT',
            self::Edp => 'EDP',
            self::Parfum => 'Parfum',
            self::Cologne => 'Cologne',
            self::Extrait => 'Extrait',
            self::Other => 'Other',
        };
    }
}

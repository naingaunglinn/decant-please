<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum Gender: string implements HasLabel
{
    case Male = 'male';
    case Female = 'female';
    case Unisex = 'unisex';

    public function getLabel(): string|Htmlable|null
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
            self::Unisex => 'Unisex',
        };
    }
}

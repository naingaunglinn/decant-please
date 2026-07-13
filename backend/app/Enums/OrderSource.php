<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum OrderSource: string implements HasLabel
{
    case Website = 'website';
    case Tiktok = 'tiktok';
    case Facebook = 'facebook';
    case Other = 'other';

    public function getLabel(): string|Htmlable|null
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::Tiktok => 'TikTok',
            self::Facebook => 'Facebook',
            self::Other => 'Other',
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Shop lifecycle (Step 34 §1) — the state the overloaded `is_active` boolean
 * conflated ("not launched" vs "owner paused" vs "we suspended them"). Only
 * `live` is served by the public API and the storefront host resolver; the other
 * three all resolve to the same generic 404, so a legacy inactive shop keeps its
 * current behaviour whichever non-live state it maps to.
 */
enum ShopStatus: string implements HasColor, HasLabel
{
    case Onboarding = 'onboarding';
    case Live = 'live';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function getLabel(): string|Htmlable|null
    {
        return $this->label();
    }

    /**
     * @return string|array<string>|null
     */
    public function getColor(): string|array|null
    {
        return $this->color();
    }

    public function label(): string
    {
        return match ($this) {
            self::Onboarding => 'Onboarding',
            self::Live => 'Live',
            self::Suspended => 'Suspended',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Onboarding => 'info',
            self::Live => 'success',
            self::Suspended => 'warning',
            self::Archived => 'gray',
        };
    }

    /** The only state the storefront/API serves. */
    public function isServable(): bool
    {
        return $this === self::Live;
    }
}

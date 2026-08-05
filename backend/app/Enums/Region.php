<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Myanmar's 15 top-level divisions — 7 States, 7 Regions, and the Union
 * Territory. Constitutionally fixed, so an enum, not a table: no join on the
 * checkout path and no "Yangon" vs "Yangon Region" drift. The label carries
 * the State/Region suffix a Myanmar customer expects to read in a dropdown;
 * the value does not. Cases are ordered alphabetically by label — the order
 * the seed file is in and the order both selects render in.
 */
enum Region: string implements HasLabel
{
    case Ayeyarwady = 'ayeyarwady';
    case Bago = 'bago';
    case Chin = 'chin';
    case Kachin = 'kachin';
    case Kayah = 'kayah';
    case Kayin = 'kayin';
    case Magway = 'magway';
    case Mandalay = 'mandalay';
    case Mon = 'mon';
    case Naypyidaw = 'naypyidaw';
    case Rakhine = 'rakhine';
    case Sagaing = 'sagaing';
    case Shan = 'shan';
    case Tanintharyi = 'tanintharyi';
    case Yangon = 'yangon';

    public function getLabel(): string|Htmlable|null
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Ayeyarwady => 'Ayeyarwady Region',
            self::Bago => 'Bago Region',
            self::Chin => 'Chin State',
            self::Kachin => 'Kachin State',
            self::Kayah => 'Kayah State',
            self::Kayin => 'Kayin State',
            self::Magway => 'Magway Region',
            self::Mandalay => 'Mandalay Region',
            self::Mon => 'Mon State',
            self::Naypyidaw => 'Naypyidaw Union Territory',
            self::Rakhine => 'Rakhine State',
            self::Sagaing => 'Sagaing Region',
            self::Shan => 'Shan State',
            self::Tanintharyi => 'Tanintharyi Region',
            self::Yangon => 'Yangon Region',
        };
    }

    public function labelMm(): string
    {
        return match ($this) {
            self::Ayeyarwady => 'ဧရာဝတီတိုင်းဒေသကြီး',
            self::Bago => 'ပဲခူးတိုင်းဒေသကြီး',
            self::Chin => 'ချင်းပြည်နယ်',
            self::Kachin => 'ကချင်ပြည်နယ်',
            self::Kayah => 'ကယားပြည်နယ်',
            self::Kayin => 'ကရင်ပြည်နယ်',
            self::Magway => 'မကွေးတိုင်းဒေသကြီး',
            self::Mandalay => 'မန္တလေးတိုင်းဒေသကြီး',
            self::Mon => 'မွန်ပြည်နယ်',
            self::Naypyidaw => 'နေပြည်တော် ပြည်ထောင်စုနယ်မြေ',
            self::Rakhine => 'ရခိုင်ပြည်နယ်',
            self::Sagaing => 'စစ်ကိုင်းတိုင်းဒေသကြီး',
            self::Shan => 'ရှမ်းပြည်နယ်',
            self::Tanintharyi => 'တနင်္သာရီတိုင်းဒေသကြီး',
            self::Yangon => 'ရန်ကုန်တိုင်းဒေသကြီး',
        };
    }

    /**
     * Parse a region cell from seed or import data, which arrives both bare
     * ("Ayeyarwady") and suffixed ("Bago Region", "Chin State") plus a couple
     * of spellings couriers actually use. Null means unrecognised — the caller
     * fails that row with a reason rather than guessing.
     */
    public static function fromLoose(string $value): ?self
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/\s+(region|state|division|union territory)$/', '', $normalized);
        $normalized = str_replace([' ', '-'], '', $normalized);

        return self::tryFrom($normalized) ?? match ($normalized) {
            'naypyitaw', 'neypyidaw' => self::Naypyidaw,
            'magwe' => self::Magway,
            'irrawaddy' => self::Ayeyarwady,
            default => null,
        };
    }
}

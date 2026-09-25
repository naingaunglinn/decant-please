<?php

namespace App\Design;

/**
 * A design's theme (step 46): three base designs, four colours and a font, all
 * keys stored in shop_designs.config — never rename one. The storefront owns
 * what each base and font look like (46b); the backend knows their names.
 */
final class Theme
{
    /** @return array<string, array{label: string, description: string}> base key => admin words, in picker order */
    public static function bases(): array
    {
        return [
            'clean' => ['label' => 'Clean · ရိုးရှင်း', 'description' => 'Light and simple. Your products come first.'],
            'bold' => ['label' => 'Bold · တောက်ပ', 'description' => 'Big photos and strong colour.'],
            'warm' => ['label' => 'Warm · နွေးထွေး', 'description' => 'Tells your story first. Good for food and services.'],
        ];
    }

    /** @return array<string, string> font key => admin label. Each maps to a stack with a Burmese face (46b). */
    public static function fonts(): array
    {
        return [
            'modern' => 'Modern',
            'classic' => 'Classic',
            'friendly' => 'Friendly',
        ];
    }

    /** The four theme colours, in form order. */
    public const COLORS = ['background', 'text', 'primary', 'primary_text'];

    /** [foreground, background] pairs that must stay readable. */
    public const CONTRAST_PAIRS = [['text', 'background'], ['primary_text', 'primary']];

    /** WCAG AA for body text. */
    public const MIN_CONTRAST = 4.5;

    /** The WCAG contrast ratio between two #rrggbb colours (1 to 21). */
    public static function contrast(string $foreground, string $background): float
    {
        $lighter = max(self::luminance($foreground), self::luminance($background));
        $darker = min(self::luminance($foreground), self::luminance($background));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}

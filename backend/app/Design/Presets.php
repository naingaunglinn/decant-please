<?php

namespace App\Design;

use InvalidArgumentException;

/**
 * Design presets (step 46): three per template, one per base design, kept as
 * data in resources/designs/presets/{template}.{base}.json — base design,
 * colours, font, section order and Burmese sample copy. Adding a template means
 * adding its three files; DesignSystemTest fails until they exist and validate.
 *
 * The Clean preset is a template's default: a shop with no published design
 * renders it, so decant's Clean is today's storefront, word for word.
 */
final class Presets
{
    public const DEFAULT_BASE = 'clean';

    /**
     * The template's presets, in Theme::bases() order.
     *
     * @return array<string, array<string, mixed>> preset key ("decant.clean") => config
     */
    public static function for(string $template): array
    {
        $presets = [];

        foreach (array_keys(Theme::bases()) as $base) {
            $key = "{$template}.{$base}";
            $presets[$key] = self::get($key);
        }

        return $presets;
    }

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        if (! preg_match('/^[a-z_]+\.[a-z_]+$/', $key)) {
            throw new InvalidArgumentException("Unknown design preset \"{$key}\".");
        }

        // once() per key: the Design page reads each preset more than once per request.
        return once(function () use ($key): array {
            $path = resource_path("designs/presets/{$key}.json");

            if (! is_file($path)) {
                throw new InvalidArgumentException("Unknown design preset \"{$key}\".");
            }

            return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        });
    }

    /** @return array<string, mixed> the design a shop with no published row renders */
    public static function default(string $template): array
    {
        return self::get($template.'.'.self::DEFAULT_BASE);
    }
}

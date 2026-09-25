<?php

namespace App\Templates;

use App\Models\ShopSetting;
use InvalidArgumentException;

/**
 * The template registry (step 37). Adding a category is adding a class here.
 */
final class Templates
{
    public const DEFAULT = 'decant';

    /** @var array<string, class-string<Template>> */
    private static array $registry = [
        'decant' => DecantTemplate::class,
    ];

    public static function get(string $key): Template
    {
        $class = self::$registry[$key] ?? throw new InvalidArgumentException("Unknown template \"{$key}\".");

        return new $class;
    }

    public static function has(string $key): bool
    {
        return isset(self::$registry[$key]);
    }

    /** The current shop's default template (shop_settings.template; decant when the row doesn't exist yet). */
    public static function forShop(): Template
    {
        return self::get(self::shopDefaultKey());
    }

    public static function shopDefaultKey(): string
    {
        // currentOrNull, never current(): a read must not create the settings row.
        return ShopSetting::currentOrNull()?->template ?? self::DEFAULT;
    }

    /**
     * A shop may mix templates only within its own group (roadmap decision 3):
     * clothes + bags yes, cafe drinks + shipped coffee beans no.
     *
     * @throws InvalidArgumentException
     */
    public static function assertAllowedForShop(string $key): void
    {
        $shopDefault = self::get(self::shopDefaultKey());

        if (self::get($key)->group() !== $shopDefault->group()) {
            throw new InvalidArgumentException(
                "Template \"{$key}\" is outside this shop's group — a {$shopDefault->key()} shop can't sell it."
            );
        }
    }

    /**
     * Tests only: register a template (a second group has no real template yet).
     *
     * @param  class-string<Template>  $class
     */
    public static function register(string $class): void
    {
        self::$registry[(new $class)->key()] = $class;
    }

    /** Tests only: drop a template register() added. */
    public static function forget(string $key): void
    {
        unset(self::$registry[$key]);
    }
}

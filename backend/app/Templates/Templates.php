<?php

namespace App\Templates;

use App\Enums\OrderStatus;
use App\Models\Shop;
use App\Models\ShopSetting;
use App\Support\TenantContext;
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
        'clothing' => ClothingTemplate::class,
    ];

    public static function get(string $key): Template
    {
        $class = self::$registry[$key] ?? throw new InvalidArgumentException("Unknown template \"{$key}\".");

        return new $class;
    }

    /** @return array<string, string> key => admin label, for a template picker */
    public static function options(): array
    {
        return array_map(fn (string $class): string => (new $class)->name(), self::$registry);
    }

    /** @return list<string> the keys whose templates pool their stock (step 40) */
    public static function pooledKeys(): array
    {
        return array_keys(array_filter(self::$registry, fn (string $class): bool => (new $class)->pooledStock()));
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
     * The one status-label resolver (step 39): the current shop's template's word
     * for $status. OrderStatus::label() — and so every badge, select, CSV row,
     * invoice and tracking receipt — reads this.
     *
     * With no tenant set (a console command) there is no shop to ask, so it falls
     * back to the category-free word: a label is not shop data, so this can't leak.
     * Memoised per shop id for the request — an orders list would otherwise read
     * shop_settings once per badge. Keyed by id, never the Shop object: an object
     * hash is reused after GC and could hand one shop another's labels.
     */
    public static function statusLabel(OrderStatus $status): string
    {
        return self::statusLabels()[$status->value] ?? $status->defaultLabel();
    }

    /** @return array<string, string> every state's label, in the same words statusLabel() gives */
    public static function statusLabels(): array
    {
        $shop = app(TenantContext::class)->get();

        if ($shop === null) {
            $labels = [];

            foreach (OrderStatus::cases() as $status) {
                $labels[$status->value] = $status->defaultLabel();
            }

            return $labels;
        }

        return self::statusLabelsFor($shop->id);
    }

    /** @return array<string, string> */
    private static function statusLabelsFor(int $shopId): array
    {
        // once() keys on the closure's captured variables, not on this method's
        // arguments: capturing $shopId is what gives each shop its own entry.
        return once(function () use ($shopId): array {
            unset($shopId); // captured for the key only; forShop() reads the context

            return self::forShop()->statusLabels();
        });
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
     * Set a shop's default template (step 38) — the studio picks it when it
     * registers a shop. Not withoutTenancy(): the settings row must be written as
     * $shop's, so the context becomes $shop for the write and is restored even on
     * a throw (the NationalGeography::seed pattern).
     */
    public static function assignToShop(Shop $shop, string $key): void
    {
        self::get($key); // unknown key → throws before anything is written

        $context = app(TenantContext::class);
        $previous = $context->get();
        $context->set($shop);

        try {
            ShopSetting::current()->update(['template' => $key]);
        } finally {
            $context->set($previous);
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

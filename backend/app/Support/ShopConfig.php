<?php

namespace App\Support;

use App\Models\Shop;
use App\Models\ShopSetting;

/**
 * The one site for per-shop configuration resolution (step 33). Every value
 * resolves the same way — **shop setting row → platform env/config default →
 * off** — so a blank at both levels means the feature is off for that shop (the
 * v11 no-op rule), and step 34's "is this shop configured?" panel reads the same
 * truth the API and the notifier do.
 *
 * Resolves for the CURRENT tenant, via ShopSetting::current() (itself
 * BelongsToShop-scoped) — never an arbitrary Shop instance, because reading
 * another shop's row cross-context would either throw (no tenant set) or be
 * silently filtered by the global scope. Callers run under the right context:
 * the API through ResolveTenant, the panel through Filament tenancy, the
 * Telegram listeners under the order's shop, and telegram:test after setting it.
 * That means zero new withoutTenancy() call sites.
 *
 * The env fallback is the platform default, not legacy debt: it is how the
 * single-shop deployment keeps working unchanged, and how several shops share one
 * platform Telegram bot (blank shop token → platform token).
 */
class ShopConfig
{
    /**
     * key => [shop_settings column, config() key for the platform default].
     * Payment keys are here so the existing /meta resolution routes through one
     * place; their behaviour is unchanged (shop value else env else null).
     */
    private const MAP = [
        'telegram.bot_token' => ['bot_token', 'services.telegram.bot_token'],
        'telegram.admin_chat_id' => ['admin_chat_id', 'services.telegram.admin_chat_id'],
        'social.tiktok' => ['tiktok_url', 'app.social.tiktok'],
        'social.facebook' => ['facebook_url', 'app.social.facebook'],
        'payment.kbzpay_name' => ['kbzpay_name', 'app.payment.kbzpay_name'],
        'payment.kbzpay_number' => ['kbzpay_number', 'app.payment.kbzpay_number'],
        'payment.wave_name' => ['wave_name', 'app.payment.wave_name'],
        'payment.wave_number' => ['wave_number', 'app.payment.wave_number'],
        'payment.instructions' => ['payment_instructions', 'app.payment.instructions'],
    ];

    /**
     * The resolved value for the current shop, or null when neither the shop nor
     * the platform has it set. `filled()` (not `?:`) so an empty string at either
     * level falls through consistently — matching the pre-33 Telegram/`/meta`
     * behaviour for every real value.
     */
    public static function get(string $key): ?string
    {
        if (! isset(self::MAP[$key])) {
            throw new \InvalidArgumentException("Unknown shop config key: {$key}");
        }

        [$column, $configKey] = self::MAP[$key];

        $shopValue = ShopSetting::current()->{$column};
        if (filled($shopValue)) {
            return (string) $shopValue;
        }

        $platformValue = config($configKey);

        return filled($platformValue) ? (string) $platformValue : null;
    }

    /** True when both halves of a paired feature resolve (e.g. Telegram needs token AND chat). */
    public static function hasAll(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (self::get($key) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a key for an EXPLICIT shop rather than the ambient tenant — the
     * Telegram listeners need `$event->order->shop`'s config, which need not be
     * the ambient context (e.g. a queued or hand-dispatched event). Switches the
     * tenant context to the target shop for the read and restores it in a finally
     * — the blessed cross-shop *set-the-context* pattern (NationalGeography::seed),
     * using only TenantContext's public API, so it is neither a withoutTenancy()
     * bypass (the budget is untouched) nor a change to the tenancy seam. During a
     * real checkout the ambient context already IS the order's shop, so the switch
     * is a harmless no-op there.
     */
    public static function forShop(Shop $shop, string $key): ?string
    {
        $context = app(TenantContext::class);
        $previous = $context->get();

        $context->set($shop);

        try {
            return self::get($key);
        } finally {
            $context->set($previous);
        }
    }

    public static function hasAllForShop(Shop $shop, string ...$keys): bool
    {
        $context = app(TenantContext::class);
        $previous = $context->get();

        $context->set($shop);

        try {
            return self::hasAll(...$keys);
        } finally {
            $context->set($previous);
        }
    }
}

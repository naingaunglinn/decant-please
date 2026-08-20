<?php

namespace App\Support;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin wrapper over Telegram's Bot API sendMessage. Deliberately not a composer
 * package — one HTTP call, no new dependency on the Heroku buildpack.
 *
 * Two hard rules, both because this is called from the checkout request path:
 * - It NEVER throws. A Telegram outage must not fail a customer's order; every
 *   failure is caught and logged, and the method just returns false.
 * - It's a no-op when unconfigured, so the whole feature is off until a decanter
 *   sets a token + chat id (blank at shop AND platform = alerts simply don't send).
 *
 * Per-shop since step 33: the token + chat id resolve through ShopConfig
 * (shop settings row → platform env → off). `*ForShop`/`sendToShop` take the
 * order's shop explicitly (the listeners' path); `isConfigured`/`sendToAdmin`
 * resolve the *current* tenant (telegram:test after setting context, and the
 * notifier's own unit tests).
 */
class TelegramNotifier
{
    /** Current tenant's Telegram config is complete (token AND chat id resolve). */
    public function isConfigured(): bool
    {
        return ShopConfig::hasAll('telegram.bot_token', 'telegram.admin_chat_id');
    }

    /** The given shop's Telegram config is complete. */
    public function isConfiguredForShop(Shop $shop): bool
    {
        return ShopConfig::hasAllForShop($shop, 'telegram.bot_token', 'telegram.admin_chat_id');
    }

    /** Message the current tenant's configured chat. */
    public function sendToAdmin(string $message): bool
    {
        return $this->send(
            ShopConfig::get('telegram.bot_token'),
            ShopConfig::get('telegram.admin_chat_id'),
            $message,
        );
    }

    /** Message a specific shop's configured chat with that shop's bot token. */
    public function sendToShop(Shop $shop, string $message): bool
    {
        return $this->send(
            ShopConfig::forShop($shop, 'telegram.bot_token'),
            ShopConfig::forShop($shop, 'telegram.admin_chat_id'),
            $message,
        );
    }

    public function send(?string $token, ?string $chatId, string $message): bool
    {
        if (! filled($token) || ! filled($chatId)) {
            Log::info('Telegram alert skipped — bot token or chat id not configured.');

            return false;
        }

        try {
            // Plain text, no parse_mode: Burmese names/addresses and Markdown/HTML
            // special characters would otherwise need escaping to avoid a 400.
            $response = Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'disable_web_page_preview' => true,
            ]);

            if ($response->failed()) {
                Log::warning('Telegram sendMessage failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            // Timeout, DNS, TLS — anything. The order still succeeds.
            Log::warning('Telegram sendMessage threw.', ['error' => $e->getMessage()]);

            return false;
        }
    }
}

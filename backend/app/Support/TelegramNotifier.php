<?php

namespace App\Support;

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
 *   sets a token + chat id (blank env = alerts simply don't send).
 */
class TelegramNotifier
{
    public function isConfigured(): bool
    {
        return filled(config('services.telegram.bot_token'))
            && filled(config('services.telegram.admin_chat_id'));
    }

    /** Message the decanter's configured chat. */
    public function sendToAdmin(string $message): bool
    {
        return $this->send((string) config('services.telegram.admin_chat_id'), $message);
    }

    public function send(?string $chatId, string $message): bool
    {
        $token = config('services.telegram.bot_token');

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

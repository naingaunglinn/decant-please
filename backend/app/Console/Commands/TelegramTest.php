<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Support\TelegramNotifier;
use Illuminate\Console\Command;

/**
 * `php artisan telegram:test {shop}` — onboarding aid. Confirms a shop's bot
 * token + chat id (its own, or the shared platform bot) actually reach Telegram,
 * so the founder isn't guessing whether that shop's alerts are wired before its
 * first real order. Per-shop since step 33: the shop argument is required — there
 * is no "the" configured chat any more.
 */
class TelegramTest extends Command
{
    protected $signature = 'telegram:test {shop : Slug of the shop to test}';

    protected $description = "Send a test message to a shop's configured admin Telegram chat";

    public function handle(TelegramNotifier $telegram): int
    {
        $slug = (string) $this->argument('shop');
        $shop = Shop::where('slug', $slug)->first();

        if ($shop === null) {
            $this->error("No shop with slug '{$slug}'.");

            return self::FAILURE;
        }

        if (! $telegram->isConfiguredForShop($shop)) {
            $this->error("Telegram is not configured for '{$slug}' — set its bot token + chat id in the shop's payment settings, or the platform TELEGRAM_* env as the shared default.");

            return self::FAILURE;
        }

        $sent = $telegram->sendToShop($shop, "✅ Decant Please! test message — {$shop->name}'s order alerts are wired up.");

        if ($sent) {
            $this->info('Sent — check your Telegram.');

            return self::SUCCESS;
        }

        $this->error('Send failed — double-check the token and chat id, and that you pressed Start on the bot. See the log for details.');

        return self::FAILURE;
    }
}

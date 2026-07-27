<?php

namespace App\Console\Commands;

use App\Support\TelegramNotifier;
use Illuminate\Console\Command;

/**
 * `php artisan telegram:test` — onboarding aid. Confirms a shop's bot token +
 * chat id actually reach Telegram, so the founder isn't guessing whether alerts
 * are wired before the first real order.
 */
class TelegramTest extends Command
{
    protected $signature = 'telegram:test';

    protected $description = 'Send a test message to the configured admin Telegram chat';

    public function handle(TelegramNotifier $telegram): int
    {
        if (! $telegram->isConfigured()) {
            $this->error('Telegram is not configured — set TELEGRAM_BOT_TOKEN and TELEGRAM_ADMIN_CHAT_ID in .env.');

            return self::FAILURE;
        }

        $sent = $telegram->sendToAdmin('✅ Decant Please! test message — your order alerts are wired up.');

        if ($sent) {
            $this->info('Sent — check your Telegram.');

            return self::SUCCESS;
        }

        $this->error('Send failed — double-check the token and chat id, and that you pressed Start on the bot. See the log for details.');

        return self::FAILURE;
    }
}

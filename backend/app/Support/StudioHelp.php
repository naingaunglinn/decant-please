<?php

namespace App\Support;

use App\Models\Shop;

/**
 * Step 45: the admin's Help button — deep links that open the seller's own
 * Telegram or Viber on a chat with the studio. The seller writes and sends;
 * the platform sends, stores and receives nothing.
 *
 * The studio's handles are one platform value for every shop
 * (`services.support.*`), never a ShopConfig read. A blank or malformed value
 * hides that channel — it never throws, because this renders on every admin
 * page and a side channel must not break the core path (P2).
 *
 * Scoping: reads only the Shop instance it is handed (the current Filament
 * tenant, or null on login / sign-up). No query.
 */
class StudioHelp
{
    /**
     * @return list<array{channel: string, label: string, url: string, new_tab: bool}>
     */
    public static function links(?Shop $shop): array
    {
        $message = self::message($shop);
        $links = [];

        $username = ltrim(trim((string) config('services.support.telegram_username')), '@');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $username) === 1) {
            $links[] = [
                'channel' => 'telegram',
                'label' => 'Telegram',
                'url' => 'https://t.me/'.$username.'?text='.rawurlencode($message),
                'new_tab' => true,
            ];
        }

        $number = PhoneVerification::normalize(config('services.support.viber_number'));
        if ($number !== null) {
            $links[] = [
                'channel' => 'viber',
                'label' => 'Viber',
                // A custom scheme in a new tab leaves an empty tab behind on a phone.
                'url' => 'viber://chat?number='.rawurlencode($number).'&draft='.rawurlencode($message),
                'new_tab' => false,
            ];
        }

        return $links;
    }

    /** The first message, pre-filled where the app supports it; names the shop. */
    public static function message(?Shop $shop): string
    {
        return $shop === null
            ? 'Hello, I need help · မင်္ဂလာပါ၊ အကူအညီ လိုပါတယ်'
            : "Hello, I need help with my shop {$shop->name} ({$shop->slug}) · မင်္ဂလာပါ၊ ဆိုင်အတွက် အကူအညီ လိုပါတယ်";
    }
}

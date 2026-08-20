<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Per-shop settings row: payment details (MMQR + KBZPay/Wave), Telegram alert
 * credentials (step 33), and storefront social links. One row per shop —
 * BelongsToShop scopes it and `unique(shop_id)` enforces it — so
 * ShopSetting::current() returns *this shop's* row, not a global singleton.
 * Managed from the admin's Payment settings page; surfaced to the storefront via
 * /api/v1/{shop}/meta. Configuration is read through App\Support\ShopConfig
 * (shop row → env default → off), never ad-hoc `?? config(...)`.
 */
#[Fillable([
    'kbzpay_name', 'kbzpay_number', 'wave_name', 'wave_number', 'payment_qr_path', 'payment_instructions',
    'bot_token', 'admin_chat_id', 'tiktok_url', 'facebook_url',
])]
class ShopSetting extends Model
{
    use BelongsToShop;

    protected function casts(): array
    {
        return [
            // Telegram credentials are secrets — Laravel's encrypted cast keeps
            // them ciphertext at rest and decrypts on access (the columns are
            // TEXT to hold the payload). Never logged, never in the API.
            'bot_token' => 'encrypted',
            'admin_chat_id' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // /meta caches the payment block for 10 minutes — drop it the instant the
        // decanter changes their details, so the storefront reflects it right away.
        // Per-shop key: bust only this shop's meta (the row is saved in its own
        // tenant context, so the context slug is this shop's — findings A5).
        static::saved(fn () => Cache::forget('api.meta.'.app(TenantContext::class)->slug()));
    }

    /** The current shop's settings row, created empty on first access. */
    public static function current(): self
    {
        return static::firstOrCreate([]);
    }

    /** Public URL to the MMQR image, or null when none uploaded. */
    public function qrUrl(): ?string
    {
        return $this->payment_qr_path
            ? Storage::disk(config('filesystems.media_disk'))->url($this->payment_qr_path)
            : null;
    }
}

<?php

namespace App\Models;

use App\Http\Controllers\Api\MetaController;
use App\Models\Concerns\BelongsToShop;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;

/**
 * Per-shop settings row: payment details (MMQR + KBZPay/Wave), Telegram alert
 * credentials (step 33), storefront social links, the shop's default
 * template (step 37; App\Templates\Templates), its enabled modules (step 41) and its
 * live storefront design (step 46, published_design_id — written only by
 * App\Design\Designs::publish()). One row per shop —
 * BelongsToShop scopes it and `unique(shop_id)` enforces it — so
 * ShopSetting::current() returns *this shop's* row, not a global singleton.
 * Managed from the admin's Payment settings page; surfaced to the storefront via
 * /api/v1/{shop}/meta. Configuration is read through App\Support\ShopConfig
 * (shop row → env default → off), never ad-hoc `?? config(...)`.
 */
#[Fillable([
    'kbzpay_name', 'kbzpay_number', 'wave_name', 'wave_number', 'payment_qr_path', 'payment_instructions',
    'bot_token', 'admin_chat_id', 'tiktok_url', 'facebook_url', 'template', 'modules',
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
            // Enabled optional features (step 41, App\Support\Modules); null = the template's defaults.
            'modules' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // /meta caches the payment block for 10 minutes — drop it the instant the
        // decanter changes their details, so the storefront reflects it right away.
        // Per-shop key: bust only this shop's meta (the row is saved in its own
        // tenant context, so the context slug is this shop's — findings A5).
        // Modules and status labels are memoised per request with once(): forget
        // them too, so a toggle or template change shows on the same request.
        static::saved(function (): void {
            Cache::forget(MetaController::cacheKey(app(TenantContext::class)->slug()));
            Once::flush();
        });
    }

    /** The current shop's settings row, created empty on first access. */
    public static function current(): self
    {
        return static::firstOrCreate([]);
    }

    /**
     * The current shop's settings row if it exists, else null — WITHOUT creating one.
     * Config *resolution* (ShopConfig) must read through this, never current(): a read
     * that writes creates empty rows and, for a shop being viewed read-only under Step
     * 34's impersonation guard, would trip that guard (a 500 on the Studio registry /
     * detail page). Returns the model so encrypted casts still decrypt on access.
     */
    public static function currentOrNull(): ?self
    {
        return static::query()->first();
    }

    /** Public URL to the MMQR image, or null when none uploaded. */
    public function qrUrl(): ?string
    {
        return $this->payment_qr_path
            ? Storage::disk(config('filesystems.media_disk'))->url($this->payment_qr_path)
            : null;
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Singleton settings row for the decanter's payment details (MMQR + KBZPay/Wave).
 * Managed from the admin's Payment settings page; surfaced to the storefront via
 * /api/v1/meta. Always exactly one row — use ShopSetting::current().
 */
#[Fillable(['kbzpay_name', 'kbzpay_number', 'wave_name', 'wave_number', 'payment_qr_path', 'payment_instructions'])]
class ShopSetting extends Model
{
    use BelongsToShop;

    protected static function booted(): void
    {
        // /meta caches the payment block for 10 minutes — drop it the instant the
        // decanter changes their details, so the storefront reflects it right away.
        // Per-shop key: bust only this shop's meta (the row is saved in its own
        // tenant context, so the context slug is this shop's — findings A5).
        static::saved(fn () => Cache::forget('api.meta.'.app(TenantContext::class)->slug()));
    }

    /** The one and only settings row, created empty on first access. */
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

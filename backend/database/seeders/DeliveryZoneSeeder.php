<?php

namespace Database\Seeders;

use App\Enums\Region;
use App\Models\DeliveryTownship;
use App\Support\NationalGeography;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds delivery geography from Royal Express's official coverage chart
 * ("Last Updated 1/8/2026" — committed as database/data/preview.webp; the
 * chart is perishable, so re-check it when the decanter next reviews fees).
 *
 * The copy mechanics live in App\Support\NationalGeography — the same code
 * runs when a new shop is registered in the panel (multi-tenancy Step 25a),
 * so a fresh install and a fresh shop get identical geography: every row
 * inactive at fee 0, idempotent by (region, name), a suspended route is a
 * CLOSED row (reversible) while unserved is no row at all.
 *
 * The one demo exception stays here, out of the shared path: Yangon activates
 * at a placeholder fee so a fresh `docker compose up` can complete a checkout —
 * the same seam the demo catalog sits behind. `decant:fresh-start` resets
 * every zone to inactive/0, so real installs go live with no invented fee.
 */
class DeliveryZoneSeeder extends Seeder
{
    private const DEMO_YANGON_FEE_MMK = 2000;

    public function run(): void
    {
        $context = app(TenantContext::class);

        NationalGeography::seed(
            $context->get(),
            fn (string $message) => $this->command?->warn($message),
        );

        $this->activateYangonForDemo();

        Cache::forget('api.delivery-zones.'.$context->slug());
    }

    private function activateYangonForDemo(): void
    {
        // Demo only in effect: touches nothing the decanter has already
        // activated or priced, and decant:fresh-start resets every zone to
        // inactive at 0 — so real installs go live with no invented fee.
        DeliveryTownship::query()
            ->where('region', Region::Yangon->value)
            ->where('is_active', false)
            ->where('fee_mmk', 0)
            ->update(['is_active' => true, 'fee_mmk' => self::DEMO_YANGON_FEE_MMK]);
    }
}

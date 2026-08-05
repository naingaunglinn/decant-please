<?php

namespace Tests;

use App\Enums\Courier;
use App\Models\DeliveryTownship;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * An active, courier-served township — the gate every checkout must pass
     * since step 30. Fee defaults to 0 (a real free-delivery zone) so existing
     * total assertions stay untouched; fee-math tests pass their own.
     */
    protected function serviceableTownship(int $fee = 0, string $name = 'Bahan', ?string $nameMm = null): DeliveryTownship
    {
        $township = DeliveryTownship::firstOrCreate(
            ['region' => 'yangon', 'name' => $name],
            ['name_mm' => $nameMm, 'fee_mmk' => $fee, 'is_active' => true],
        );

        $township->couriers()->firstOrCreate(
            ['courier' => Courier::RoyalExpress->value],
            ['courier_name' => $name, 'is_available' => true],
        );

        return $township;
    }
}

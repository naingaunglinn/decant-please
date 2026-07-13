<?php

namespace Database\Seeders;

use App\Enums\PromoType;
use App\Models\PromoCode;
use Illuminate\Database\Seeder;

class PromoCodeSeeder extends Seeder
{
    public function run(): void
    {
        PromoCode::updateOrCreate(['code' => 'WELCOME10'], [
            'type' => PromoType::Percent,
            'value' => 10,
            'max_discount_mmk' => 20000,
            'min_order_mmk' => 50000,
            'is_active' => true,
        ]);

        PromoCode::updateOrCreate(['code' => 'DECANT5K'], [
            'type' => PromoType::Fixed,
            'value' => 5000,
            'min_order_mmk' => 30000,
            'usage_limit' => 50,
            'is_active' => true,
        ]);
    }
}

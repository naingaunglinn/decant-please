<?php

namespace Database\Factories;

use App\Enums\ShopStatus;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => ShopStatus::Live,
        ];
    }

    /** A non-serving shop (was `is_active = false`) — maps to onboarding (Step 34 §1). */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ShopStatus::Onboarding]);
    }

    public function status(ShopStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}

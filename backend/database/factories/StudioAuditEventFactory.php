<?php

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Models\Shop;
use App\Models\StudioAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudioAuditEvent>
 */
class StudioAuditEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory()->state(['is_studio' => true]),
            'shop_id' => Shop::factory(),
            'action' => AuditAction::PanelEnter,
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'phpunit',
            'metadata' => null,
        ];
    }
}

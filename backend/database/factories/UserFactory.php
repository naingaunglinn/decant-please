<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A platform (studio) admin. The `studio_admin` role IS the grant now — issue
     * #110 retired the `is_studio` column, so tests say `->studio()` instead of
     * `create(['is_studio' => true])`. Guarded so the factory still works if it runs
     * before the roles migration has seeded `studio_admin`.
     */
    public function studio(): static
    {
        return $this->afterCreating(function (User $user): void {
            // Fail loud if the role isn't seeded. A silent skip would leave the
            // user role-less, and a test that calls ->studio() and expects a
            // denial would then pass for the wrong reason (issue #110 review).
            if (! Schema::hasTable('roles') || ! Role::where('name', 'studio_admin')->exists()) {
                throw new RuntimeException('UserFactory::studio() requires the studio_admin role — run the role migration before creating a studio user.');
            }

            $user->assignRole('studio_admin');
        });
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $password = env('ADMIN_PASSWORD');

        if (! $password) {
            throw new RuntimeException('ADMIN_PASSWORD is not set in .env — refusing to seed the admin user without one.');
        }

        User::updateOrCreate(
            ['email' => 'admin@decantplease.local'],
            ['name' => 'Admin', 'password' => Hash::make($password)],
        );

        $this->call([
            CatalogSeeder::class,
            OrderSeeder::class,
            PromoCodeSeeder::class,
        ]);
    }
}

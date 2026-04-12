<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Central `users` holds optional global accounts (see central migrations).
     * Tenant app users (e.g. tenant@gmail.com) come from {@see TenantSeeder}.
     *
     * Note: Do not use WithoutModelEvents on this seeder.
     * Stancl assigns tenant UUIDs in a `creating` listener; suppressing model events breaks that.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => 'password',
                'active' => true,
            ]
        );

        $this->call([
            TenantSeeder::class,
        ]);
    }
}

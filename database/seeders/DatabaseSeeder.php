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
        $admin = User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => 'password',
                'is_active' => true,
            ]
        );
        // Platform administrator for the central API (not mass-assignable on purpose).
        $admin->forceFill(['is_super_admin' => true])->save();

        $this->call([
            ModuleSeeder::class,
            TenantSeeder::class,
        ]);
    }
}

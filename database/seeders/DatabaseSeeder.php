<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Default Superadmin account
        User::firstOrCreate(
            ['username' => 'superadmin'],
            [
                'name' => 'System Superadmin',
                'email' => 'superadmin@calltek.local',
                'password' => Hash::make('password'),
                'role' => User::ROLE_SUPERADMIN,
                'department' => 'IT Leadership',
                'job_title' => 'Director of Engineering',
                'is_active' => true,
            ],
        );
    }
}

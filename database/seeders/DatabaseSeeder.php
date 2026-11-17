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
        $password = Hash::make('password');

        // Users: Superadmin, 3 Engineers, 3 Standard Users
        User::create([
            'name' => 'System Superadmin',
            'username' => 'superadmin',
            'email' => 'superadmin@calltek.local',
            'password' => $password,
            'role' => User::ROLE_SUPERADMIN,
            'department' => 'IT Leadership',
            'job_title' => 'Director of Engineering',
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Lead Systems Engineer',
            'username' => 'engineer1',
            'email' => 'engineer1@calltek.local',
            'password' => $password,
            'role' => User::ROLE_ENGINEER,
            'department' => 'Platform Engineering',
            'job_title' => 'Principal Systems Engineer',
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Network & NOC Engineer',
            'username' => 'engineer2',
            'email' => 'engineer2@calltek.local',
            'password' => $password,
            'role' => User::ROLE_ENGINEER,
            'department' => 'Network Engineering',
            'job_title' => 'Senior NOC Infrastructure Engineer',
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Full-Stack Developer',
            'username' => 'engineer3',
            'email' => 'engineer3@calltek.local',
            'password' => $password,
            'role' => User::ROLE_ENGINEER,
            'department' => 'Application Development',
            'job_title' => 'Senior Full-Stack Engineer',
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Operations Supervisor',
            'username' => 'user1',
            'email' => 'user1@calltek.local',
            'password' => $password,
            'role' => User::ROLE_USER,
            'department' => 'Technical Support Operations',
            'job_title' => 'Operations Floor Supervisor',
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Billing Specialist',
            'username' => 'user2',
            'email' => 'user2@calltek.local',
            'password' => $password,
            'role' => User::ROLE_USER,
            'department' => 'Finance & Billing',
            'job_title' => 'Senior Billing Analyst',
            'is_active' => true,
        ]);

        User::create([
            'name' => 'NOC Analyst',
            'username' => 'user3',
            'email' => 'user3@calltek.local',
            'password' => $password,
            'role' => User::ROLE_USER,
            'department' => 'Network Operations Center',
            'job_title' => 'Tier 2 NOC Analyst',
            'is_active' => true,
        ]);
    }
}

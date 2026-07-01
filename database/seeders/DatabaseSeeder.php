<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Run role seeder first
        $this->call([
            RoleSeeder::class,
        ]);

        User::create([
            'uuid' => Str::uuid(),

            // Better than hardcoding 1
            'role_id' => Role::where('name', 'system_administrator')->value('id'),

            'first_name' => 'System',
            'middle_name' => null,
            'last_name' => 'Administrator',
            'suffix' => null,

            'email' => 'admin@servora.com',
            'email_verified_at' => now(),

            'password' => Hash::make('password'),

            'phone_number' => null,
            'avatar' => null,

            'account_status' => 'Active',

            'last_login_at' => null,

            'remember_token' => Str::random(10),
        ]);
    }
}
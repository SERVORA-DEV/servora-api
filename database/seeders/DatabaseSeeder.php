<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserPermission;
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
        $admin = User::create([
            'uuid' => Str::uuid(),

            'role' => 'system_administrator',

            'username' => 'admin',

            'first_name' => 'System',
            'middle_name' => null,
            'last_name' => 'Administrator',
            'suffix' => null,

            'email' => 'admin@servora.com',
            'email_verified_at' => now(),

            'password' => Hash::make('servoraPassword'),

            'phone_number' => null,
            'profile_photo' => null,

            'account_status' => 'Active',

            'remember_token' => Str::random(10),
        ]);

        UserPermission::create(
            array_merge(
                ['user_id' => $admin->id],
                array_fill_keys(config('permission.system_administrator'), true)
            )
        );  
    }
}
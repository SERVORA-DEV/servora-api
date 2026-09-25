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
        $audience = User::audienceForRole('system_administrator');

        // Looked up first so re-running `php artisan db:seed` doesn't fail on
        // the unique email once the admin exists.
        $admin = User::where('email', 'admin@servora.com')->where('audience', $audience)->first()
            ?? new User();

        // forceFill: `audience` isn't mass-assignable, and it has to be set
        // here because WithoutModelEvents (above) switches off User's saving
        // hook that normally derives it from the role — the column is NOT NULL.
        $admin->exists || $admin->forceFill([
            'uuid' => Str::uuid(),

            'role' => 'system_administrator',
            'audience' => $audience,

            'email' => 'admin@servora.com',
            'username' => 'admin',

            'first_name' => 'System',
            'middle_name' => null,
            'last_name' => 'Administrator',
            'suffix' => null,

            'email_verified_at' => now(),

            'password' => Hash::make('servoraPassword'),

            'phone_number' => null,
            'profile_photo' => null,

            'account_status' => 'Active',

            'remember_token' => Str::random(10),
        ])->save();

        UserPermission::updateOrCreate(
            ['user_id' => $admin->id],
            array_fill_keys(config('permission.system_administrator'), true),
        );
    }
}
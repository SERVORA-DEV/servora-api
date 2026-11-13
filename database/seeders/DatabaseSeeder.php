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

        // Keep the original administrator even after they change their email.
        // For an existing installation, the first admin is the seeded account.
        $admin = User::where('role', 'system_administrator')->where('is_primary_admin', true)->first()
            ?? User::where('role', 'system_administrator')->orderBy('id')->first()
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

        $admin->forceFill(['is_primary_admin' => true])->save();

        UserPermission::updateOrCreate(
            ['user_id' => $admin->id],
            array_fill_keys(config('permission.system_administrator'), true),
        );

        // The platform's starter catalog of service templates.
        $this->call(ServiceTemplateSeeder::class);
    }
}

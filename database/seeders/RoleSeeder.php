<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'system_administrator',
                'description' => 'Manages the entire SERVORA platform.',
            ],
            [
                'name' => 'business_owner',
                'description' => 'Owns and manages a spa business.',
            ],
            [
                'name' => 'manager',
                'description' => 'Manages assigned branches and business operations.',
            ],
            [
                'name' => 'front_officer',
                'description' => 'Handles daily customer transactions and reservations.',
            ],
            [
                'name' => 'client',
                'description' => 'Books appointments and manages personal reservations.',
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['name' => $role['name']],
                [
                    'uuid' => Str::uuid(),
                    'description' => $role['description'],
                ]
            );
        }
    }
}
<?php

namespace Database\Seeders;

use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\User;
use App\Service\Business\StaffService;
use Illuminate\Database\Seeder;

class StaffSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $owner = User::where('role', 'business_owner')->first();
        $business = $owner ? SpaBusiness::where('owner_id', $owner->id)->first() : null;

        if (! $owner || ! $business) {
            $this->command->warn('No business_owner with a spa business found — run onboarding first, then re-run this seeder.');
            return;
        }

        $branches = $business->branches;

        if ($branches->isEmpty()) {
            $this->command->warn('No branches found for this business — nothing to seed staff for.');
            return;
        }

        $staffService = app(StaffService::class);

        // Keyed by branch_name so each branch gets its own distinct roster —
        // a branch that already has a manager (e.g. Acacia) only fills in
        // the remaining roles rather than adding a second one.
        $rosterByBranch = [
            'Serenity - Acacia' => [
                ['first_name' => 'Andrea', 'last_name' => 'Villanueva', 'gender' => 'Female', 'role' => 'frontdesk', 'employment_type' => 'Full-Time', 'birth_date' => '1998-04-12', 'hire_date' => '2025-02-01', 'phone_number' => '09171234501', 'emergency_contact_name' => 'Rosa Villanueva', 'emergency_contact_number' => '09171234502'],
                ['first_name' => 'Michael', 'last_name' => 'Santos', 'gender' => 'Male', 'role' => 'therapist', 'employment_type' => 'Full-Time', 'birth_date' => '1995-09-23', 'hire_date' => '2024-11-15', 'phone_number' => '09171234503', 'emergency_contact_name' => 'Liza Santos', 'emergency_contact_number' => '09171234504'],
                ['first_name' => 'Kristine', 'last_name' => 'Bautista', 'gender' => 'Female', 'role' => 'therapist', 'employment_type' => 'Part-Time', 'birth_date' => '1997-01-30', 'hire_date' => '2025-05-10', 'phone_number' => '09171234505', 'emergency_contact_name' => 'Noel Bautista', 'emergency_contact_number' => '09171234506'],
                ['first_name' => 'Paolo', 'last_name' => 'Reyes', 'gender' => 'Male', 'role' => 'therapist', 'employment_type' => 'Contractual', 'birth_date' => '1999-06-18', 'hire_date' => '2026-01-20', 'phone_number' => '09171234507', 'emergency_contact_name' => 'Grace Reyes', 'emergency_contact_number' => '09171234508'],
            ],
            'Serenity - Buhangin' => [
                ['first_name' => 'Joanna', 'last_name' => 'Cruz', 'gender' => 'Female', 'role' => 'manager', 'employment_type' => 'Full-Time', 'birth_date' => '1990-03-05', 'hire_date' => '2024-06-01', 'phone_number' => '09181234501', 'emergency_contact_name' => 'Ed Cruz', 'emergency_contact_number' => '09181234502'],
                ['first_name' => 'Carla', 'last_name' => 'Mendoza', 'gender' => 'Female', 'role' => 'frontdesk', 'employment_type' => 'Full-Time', 'birth_date' => '1999-11-09', 'hire_date' => '2025-03-15', 'phone_number' => '09181234503', 'emergency_contact_name' => 'Fe Mendoza', 'emergency_contact_number' => '09181234504'],
                ['first_name' => 'Ryan', 'last_name' => 'Torres', 'gender' => 'Male', 'role' => 'therapist', 'employment_type' => 'Full-Time', 'birth_date' => '1994-07-22', 'hire_date' => '2024-08-01', 'phone_number' => '09181234505', 'emergency_contact_name' => 'Ana Torres', 'emergency_contact_number' => '09181234506'],
                ['first_name' => 'Angela', 'last_name' => 'Ramos', 'gender' => 'Female', 'role' => 'therapist', 'employment_type' => 'Full-Time', 'birth_date' => '1996-12-02', 'hire_date' => '2025-01-10', 'phone_number' => '09181234507', 'emergency_contact_name' => 'Rico Ramos', 'emergency_contact_number' => '09181234508'],
                ['first_name' => 'Mark', 'last_name' => 'Villareal', 'gender' => 'Male', 'role' => 'therapist', 'employment_type' => 'Part-Time', 'birth_date' => '2000-02-14', 'hire_date' => '2026-02-01', 'phone_number' => '09181234509', 'emergency_contact_name' => 'Susan Villareal', 'emergency_contact_number' => '09181234510'],
            ],
        ];

        foreach ($branches as $branch) {
            $roster = $rosterByBranch[$branch->branch_name] ?? [];

            foreach ($roster as $member) {
                $exists = Staff::where('spa_branch_id', $branch->id)
                    ->where('first_name', $member['first_name'])
                    ->where('last_name', $member['last_name'])
                    ->exists();

                if ($exists) {
                    $this->command->info("Staff already exists, skipped: {$branch->branch_name} / {$member['first_name']} {$member['last_name']}");
                    continue;
                }

                $payload = $member;
                $payload['spa_branch_uuid'] = $branch->uuid;

                $staffService->createStaff($owner, $payload);
                $this->command->info("Staff created: {$branch->branch_name} / {$member['first_name']} {$member['last_name']} ({$member['role']})");
            }
        }
    }
}

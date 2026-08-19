<?php

namespace Database\Seeders;

use App\Models\Facility;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Service\Business\FacilityService;
use Illuminate\Database\Seeder;

class FacilitySeeder extends Seeder
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
            $this->command->warn('No branches found for this business — nothing to seed facilities for.');
            return;
        }

        $facilityService = app(FacilityService::class);

        $templates = [
            [
                'name' => 'Massage Room 1',
                'description' => 'A quiet single-massage room with dimmable lighting.',
                'type' => 'Room',
                'capacity' => 1,
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Dimmable Lighting', 'Sound System'],
            ],
            [
                'name' => 'Massage Room 2',
                'description' => 'A second single-massage room with aromatherapy setup.',
                'type' => 'Room',
                'capacity' => 1,
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Dimmable Lighting', 'Aromatherapy Diffuser'],
            ],
            [
                'name' => 'Facial Treatment Room',
                'description' => 'Dedicated room for facials and skin treatments.',
                'type' => 'Room',
                'capacity' => 1,
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Magnifying Lamp', 'Steamer'],
            ],
            [
                'name' => 'Couples Suite',
                'description' => 'A larger suite for side-by-side treatments.',
                'type' => 'Couples Room',
                'capacity' => 2,
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Private Shower', 'Sound System'],
            ],
            [
                'name' => 'VIP Suite',
                'description' => 'Premium suite with private amenities for VIP guests.',
                'type' => 'VIP Room',
                'capacity' => 2,
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Private Shower', 'Mini Fridge', 'Sound System'],
            ],
        ];

        foreach ($branches as $index => $branch) {
            foreach ($templates as $templateIndex => $template) {
                $exists = Facility::where('spa_branch_id', $branch->id)
                    ->where('name', $template['name'])
                    ->exists();

                if ($exists) {
                    $this->command->info("Facility already exists, skipped: {$branch->branch_name} / {$template['name']}");
                    continue;
                }

                $payload = $template;

                // Vary one room's status across branches for realistic
                // variety instead of every branch looking identical.
                if ($index > 0 && $templateIndex === 3) {
                    $payload['status'] = 'Under Maintenance';
                    $payload['is_available'] = false;
                }

                $payload['spa_branch_uuid'] = $branch->uuid;

                $facilityService->createFacility($owner, $payload);
                $this->command->info("Facility created: {$branch->branch_name} / {$template['name']}");
            }
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Facility;
use App\Models\Service;
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
        // Every business with an owner gets seeded, not just "the first
        // business_owner found" — this is a multi-tenant system (confirmed
        // multiple unrelated businesses can coexist), so picking one
        // arbitrary owner silently skips every other tenant's data.
        $businesses = SpaBusiness::whereNotNull('owner_id')->with('branches')->get();

        if ($businesses->isEmpty()) {
            $this->command->warn('No spa business found — run onboarding first, then re-run this seeder.');
            return;
        }

        $facilityService = app(FacilityService::class);

        foreach ($businesses as $business) {
            $this->seedForBusiness($business, $facilityService);
        }
    }

    private function seedForBusiness(SpaBusiness $business, FacilityService $facilityService): void
    {
        $owner = User::find($business->owner_id);
        $branches = $business->branches;

        if (! $owner || $branches->isEmpty()) {
            $this->command->warn("Skipping {$business->business_name}: no owner or no branches.");
            return;
        }

        // service_names is resolved to service_ids per business below —
        // rooms whose category has no matching real service in this
        // catalog yet (Waxing, Lash & Brow, Makeup, Body Treatment) are
        // deliberately left unassigned rather than skipped: an unrestricted
        // room (no services configured) is a valid, supported state — see
        // FacilityRepository::listAvailableForBranches and
        // AppointmentService::checkRoomEligibility.
        $templates = [
            [
                'name' => 'Massage Room 1',
                'description' => 'A quiet single-massage room with dimmable lighting.',
                'category' => 'Massage',
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Dimmable Lighting', 'Sound System'],
                'service_names' => ['Swedish Massage', 'Deep Tissue Massage', 'Hand Massage'],
            ],
            [
                'name' => 'Massage Room 2',
                'description' => 'A second single-massage room with aromatherapy setup.',
                'category' => 'Massage',
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Dimmable Lighting', 'Aromatherapy Diffuser'],
                'service_names' => ['Swedish Massage', 'Deep Tissue Massage'],
            ],
            [
                'name' => 'Facial Treatment Room',
                'description' => 'Dedicated room for facials and skin treatments.',
                'category' => 'Facial',
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Magnifying Lamp', 'Steamer'],
                'service_names' => ['Classic Facial'],
            ],
            [
                'name' => 'Hair Station',
                'description' => 'Open-concept station for hair spa treatments.',
                'category' => 'Hair',
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Reclining Wash Chair'],
                'service_names' => ['Hair Spa Treatment'],
            ],
            [
                'name' => 'Nail Room',
                'description' => 'Manicure and pedicure room with ventilation.',
                'category' => 'Nails',
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Ventilation System'],
                'service_names' => ['Classic Manicure', 'Classic Pedicure'],
            ],
            [
                'name' => 'Waxing Room',
                'description' => 'Private room for waxing services.',
                'category' => 'Waxing',
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Privacy Curtain'],
                'service_names' => [],
            ],
            [
                'name' => 'Lash & Brow Studio',
                'description' => 'Dedicated studio for lash and brow services.',
                'category' => 'Lash & Brow',
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Magnifying Lamp'],
                'service_names' => [],
            ],
            [
                'name' => 'Makeup Room',
                'description' => 'Vanity-lit room for makeup application.',
                'category' => 'Makeup',
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Vanity Lighting'],
                'service_names' => [],
            ],
            [
                'name' => 'Body Treatment Room',
                'description' => 'Room for body scrubs and wraps.',
                'category' => 'Body Treatment',
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Private Shower'],
                'service_names' => [],
            ],
            [
                'name' => 'Couples Suite',
                'description' => 'A larger suite for side-by-side treatments.',
                'category' => 'Couples',
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Private Shower', 'Sound System'],
                'service_names' => ['Swedish Massage', 'Deep Tissue Massage'],
            ],
            [
                'name' => 'VIP Suite',
                'description' => 'Premium suite with private amenities for VIP guests.',
                'category' => 'VIP',
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Private Shower', 'Mini Fridge', 'Sound System'],
                'service_names' => ['Swedish Massage', 'Classic Facial'],
            ],
            [
                'name' => 'Wellness Room',
                'description' => 'Calming room for relaxation-focused treatments.',
                'category' => 'Wellness',
                'status' => 'Available',
                'is_available' => true,
                'amenities' => ['Aromatherapy Diffuser'],
                'service_names' => ['Hair Spa Treatment'],
            ],
        ];

        $serviceIdsByName = Service::where('spa_business_id', $business->id)->pluck('uuid', 'name');

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
                $payload['service_ids'] = collect($payload['service_names'])
                    ->map(fn ($name) => $serviceIdsByName->get($name))
                    ->filter()
                    ->values()
                    ->all();
                unset($payload['service_names']);

                // Vary one room's status across branches for realistic
                // variety instead of every branch looking identical.
                if ($index > 0 && $templateIndex === 5) {
                    $payload['status'] = 'Maintenance';
                    $payload['is_available'] = false;
                }

                $payload['spa_branch_uuid'] = $branch->uuid;

                $facilityService->createFacility($owner, $payload);
                $this->command->info("Facility created: {$branch->branch_name} / {$template['name']}");
            }
        }
    }
}

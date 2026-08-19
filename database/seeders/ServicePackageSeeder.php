<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\Service;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Service\Business\PackageService;
use App\Service\Business\ServiceService;
use Illuminate\Database\Seeder;

class ServicePackageSeeder extends Seeder
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

        $serviceService = app(ServiceService::class);
        $packageService = app(PackageService::class);

        $serviceDefs = [
            [
                'name' => 'Swedish Massage',
                'code' => 'SWM',
                'description' => 'A gentle full-body massage using long, flowing strokes to ease tension and promote relaxation.',
                'variants' => [
                    ['duration_minutes' => 30, 'price' => 450.00, 'commission_amount' => 50.00, 'loyalty_points' => 5],
                    ['duration_minutes' => 60, 'price' => 800.00, 'commission_amount' => 90.00, 'loyalty_points' => 8],
                    ['duration_minutes' => 90, 'price' => 1100.00, 'commission_amount' => 120.00, 'loyalty_points' => 11],
                ],
            ],
            [
                'name' => 'Deep Tissue Massage',
                'code' => 'DTM',
                'description' => 'Firm, targeted pressure to release chronic muscle tension in the deeper layers of tissue.',
                'variants' => [
                    ['duration_minutes' => 60, 'price' => 900.00, 'commission_amount' => 100.00, 'loyalty_points' => 9],
                    ['duration_minutes' => 90, 'price' => 1250.00, 'commission_amount' => 140.00, 'loyalty_points' => 12],
                ],
            ],
            [
                'name' => 'Hot Stone Massage',
                'code' => 'HSM',
                'description' => 'Heated basalt stones combined with massage to melt away tension and improve circulation.',
                'variants' => [
                    ['duration_minutes' => 60, 'price' => 950.00, 'commission_amount' => 105.00, 'loyalty_points' => 9],
                ],
            ],
            [
                'name' => 'Classic Facial',
                'code' => 'CFC',
                'description' => 'Cleansing, exfoliation, and hydration for a refreshed, glowing complexion.',
                'variants' => [
                    ['duration_minutes' => 45, 'price' => 600.00, 'commission_amount' => 70.00, 'loyalty_points' => 6],
                ],
            ],
            [
                'name' => 'Anti-Aging Facial',
                'code' => 'AAF',
                'description' => 'A collagen-boosting treatment targeting fine lines and uneven skin tone.',
                'variants' => [
                    ['duration_minutes' => 60, 'price' => 1200.00, 'commission_amount' => 130.00, 'loyalty_points' => 12],
                ],
            ],
            [
                'name' => 'Classic Manicure',
                'code' => 'CMN',
                'description' => 'Nail shaping, cuticle care, and polish for neat, healthy-looking hands.',
                'variants' => [
                    ['duration_minutes' => 30, 'price' => 250.00, 'commission_amount' => 30.00, 'loyalty_points' => 2],
                ],
            ],
            [
                'name' => 'Classic Pedicure',
                'code' => 'CPD',
                'description' => 'A relaxing foot soak with nail shaping, cuticle care, and polish.',
                'variants' => [
                    ['duration_minutes' => 45, 'price' => 300.00, 'commission_amount' => 35.00, 'loyalty_points' => 3],
                ],
            ],
            [
                'name' => 'Gel Manicure',
                'code' => 'GMN',
                'description' => 'Long-lasting, chip-resistant gel polish over a classic manicure.',
                'variants' => [
                    ['duration_minutes' => 45, 'price' => 400.00, 'commission_amount' => 45.00, 'loyalty_points' => 4],
                ],
            ],
            [
                'name' => 'Hair Spa Treatment',
                'code' => 'HST',
                'description' => 'Deep-conditioning scalp massage and treatment to restore shine and strength.',
                'variants' => [
                    ['duration_minutes' => 60, 'price' => 700.00, 'commission_amount' => 80.00, 'loyalty_points' => 7],
                ],
            ],
            [
                'name' => 'Body Scrub',
                'code' => 'BSC',
                'description' => 'An exfoliating full-body treatment leaving skin smooth and refreshed.',
                'variants' => [
                    ['duration_minutes' => 45, 'price' => 650.00, 'commission_amount' => 70.00, 'loyalty_points' => 6],
                ],
            ],
        ];

        /** @var array<string, Service> $services */
        $services = [];

        foreach ($serviceDefs as $def) {
            $existing = Service::where('spa_business_id', $business->id)
                ->where('name', $def['name'])
                ->with('variants')
                ->first();

            if ($existing) {
                $services[$def['name']] = $existing;
                $this->command->info("Service already exists, skipped: {$def['name']}");
                continue;
            }

            $resource = $serviceService->createService($owner, $def);
            $services[$def['name']] = $resource->resource;
            $this->command->info("Service created: {$def['name']}");
        }

        // Resolves a variant's uuid by service name + duration, for building
        // package line items below. Falls back to any variant on that
        // service if the exact duration isn't available — e.g. a service
        // that already existed before this seeder ran may only have some of
        // the durations listed in $serviceDefs above.
        $variantUuid = function (string $serviceName, int $durationMinutes) use ($services) {
            $variants = $services[$serviceName]->variants;
            return ($variants->firstWhere('duration_minutes', $durationMinutes) ?? $variants->first())?->uuid;
        };

        $packageDefs = [
            [
                'name' => 'Relax & Renew Package',
                'code' => 'PKG-RR',
                'description' => 'A Swedish massage paired with a classic facial for full-body relaxation.',
                'default_price' => 1250.00,
                'default_commission_amount' => 140.00,
                'loyalty_points' => 13,
                'services' => [
                    ['service_variant_uuid' => $variantUuid('Swedish Massage', 60), 'quantity' => 1],
                    ['service_variant_uuid' => $variantUuid('Classic Facial', 45), 'quantity' => 1],
                ],
            ],
            [
                'name' => 'Ultimate Pampering Package',
                'code' => 'PKG-UP',
                'description' => 'Deep tissue massage, anti-aging facial, and a classic pedicure in one indulgent session.',
                'default_price' => 2900.00,
                'default_commission_amount' => 320.00,
                'loyalty_points' => 30,
                'services' => [
                    ['service_variant_uuid' => $variantUuid('Deep Tissue Massage', 90), 'quantity' => 1],
                    ['service_variant_uuid' => $variantUuid('Anti-Aging Facial', 60), 'quantity' => 1],
                    ['service_variant_uuid' => $variantUuid('Classic Pedicure', 45), 'quantity' => 1],
                ],
            ],
            [
                'name' => 'Mani-Pedi Combo',
                'code' => 'PKG-MP',
                'description' => 'A classic manicure and pedicure duo for hands and feet in one visit.',
                'default_price' => 500.00,
                'default_commission_amount' => 60.00,
                'loyalty_points' => 5,
                'services' => [
                    ['service_variant_uuid' => $variantUuid('Classic Manicure', 30), 'quantity' => 1],
                    ['service_variant_uuid' => $variantUuid('Classic Pedicure', 45), 'quantity' => 1],
                ],
            ],
            [
                'name' => 'Bridal Glow Package',
                'code' => 'PKG-BG',
                'description' => 'Anti-aging facial, hair spa treatment, and gel manicure to get ready for the big day.',
                'default_price' => 2100.00,
                'default_commission_amount' => 230.00,
                'loyalty_points' => 21,
                'services' => [
                    ['service_variant_uuid' => $variantUuid('Anti-Aging Facial', 60), 'quantity' => 1],
                    ['service_variant_uuid' => $variantUuid('Hair Spa Treatment', 60), 'quantity' => 1],
                    ['service_variant_uuid' => $variantUuid('Gel Manicure', 45), 'quantity' => 1],
                ],
            ],
            [
                'name' => "Couple's Retreat",
                'code' => 'PKG-CR',
                'description' => 'Two hot stone massages, side by side — perfect for couples or best friends.',
                'default_price' => 1800.00,
                'default_commission_amount' => 200.00,
                'loyalty_points' => 18,
                'services' => [
                    ['service_variant_uuid' => $variantUuid('Hot Stone Massage', 60), 'quantity' => 2],
                ],
            ],
        ];

        foreach ($packageDefs as $def) {
            $exists = Package::where('spa_business_id', $business->id)
                ->where('name', $def['name'])
                ->exists();

            if ($exists) {
                $this->command->info("Package already exists, skipped: {$def['name']}");
                continue;
            }

            $packageService->createPackage($owner, $def);
            $this->command->info("Package created: {$def['name']}");
        }
    }
}

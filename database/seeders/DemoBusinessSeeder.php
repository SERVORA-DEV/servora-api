<?php

namespace Database\Seeders;

use App\Models\BranchSchedule;
use App\Models\CustomerProgram;
use App\Models\Facility;
use App\Models\OwnerIdentityVerification;
use App\Models\Package;
use App\Models\Service;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Service\Business\AccountService;
use App\Service\Business\BranchScheduleService;
use App\Service\Business\BusinessSettingsService;
use App\Service\Business\CustomerProgramService;
use App\Service\Business\FacilityService;
use App\Service\Business\PackageService;
use App\Service\Business\ServiceService;
use App\Service\Business\SpaBranchService;
use App\Service\Business\StaffScheduleService;
use App\Service\Business\StaffService;
use Illuminate\Database\Seeder;
use Illuminate\Http\JsonResponse;
use RuntimeException;

// A demo spa that's ready to operate: "Lotus Haven Spa" with two verified
// Davao branches, opening hours, services adopted from the admin's service
// templates (priced per branch), packages, rooms, staff with weekly
// schedules and skills, manager/front-desk logins, payment settings and
// customer programs. No clients, appointments or attendance — the front desk
// creates those by working.
//
// Everything goes through the same service classes the web app uses, acting
// as the demo owner, and is looked up by name first, so re-running only
// fills in what's missing. RemoveDemoBusinessSeeder takes it all out again.
//
// Logins (owner + 4 staff) use DEMO_PASSWORD from .env; the fallback below is
// for local development only.
class DemoBusinessSeeder extends Seeder
{
    public const BUSINESS = 'Lotus Haven Spa';
    public const OWNER_EMAIL = 'owner@lotushaven.demo';

    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private const BRANCHES = [
        'LNG' => [
            'branch_name' => 'Lotus Haven – Lanang',
            'email' => 'lanang@lotushaven.demo',
            'phone_number' => '09171230001',
            'description' => 'Our flagship spa near the Lanang business district — eight treatment rooms, a couples suite and a nail lounge.',
            'promo_text' => 'Open until 10 PM daily',
            'latitude' => 7.1003500,
            'longitude' => 125.6310800,
            'formatted_address' => 'J.P. Laurel Avenue, Lanang, Davao City, Davao del Sur',
            'permit_number' => 'BP-2026-004871',
        ],
        'MTN' => [
            'branch_name' => 'Lotus Haven – Matina',
            'email' => 'matina@lotushaven.demo',
            'phone_number' => '09171230002',
            'description' => 'A quiet neighborhood spa in Matina for massages, facials and nail care after work.',
            'promo_text' => 'Walk-ins welcome',
            'latitude' => 7.0589900,
            'longitude' => 125.5883700,
            'formatted_address' => 'MacArthur Highway, Matina, Davao City, Davao del Sur',
            'permit_number' => 'BP-2026-005236',
        ],
    ];

    // Adopted from ServiceTemplateSeeder by name. Lanang charges a little
    // more for massages.
    private const SERVICES = [
        'Swedish Massage', 'Deep Tissue Massage', 'Hilot', 'Hot Stone Massage', 'Foot Reflexology',
        'Back and Shoulder Massage', 'Classic Facial', 'Anti-Aging Facial', 'Body Scrub', 'Hair Spa Treatment',
        'Classic Manicure', 'Classic Pedicure', 'Gel Manicure', 'Couples Massage', 'Ventosa Cupping',
    ];

    private const PACKAGES = [
        ['name' => 'Relax & Renew', 'code' => 'PKG-RR', 'price' => 1550, 'minutes' => 120, 'points' => 20,
            'description' => 'A 60-minute Swedish massage followed by a classic facial.',
            'items' => [['Swedish Massage', 60, 1], ['Classic Facial', 60, 1]]],
        ['name' => 'Mani-Pedi Combo', 'code' => 'PKG-MP', 'price' => 520, 'minutes' => 75, 'points' => 5,
            'description' => 'Classic manicure and pedicure in one visit.',
            'items' => [['Classic Manicure', 30, 1], ['Classic Pedicure', 45, 1]]],
        ['name' => 'Bridal Glow', 'code' => 'PKG-BG', 'price' => 2600, 'minutes' => 195, 'points' => 30,
            'description' => 'Anti-aging facial, hair spa and gel manicure before the big day.',
            'items' => [['Anti-Aging Facial', 75, 1], ['Hair Spa Treatment', 60, 1], ['Gel Manicure', 60, 1]]],
        ['name' => "Couple's Retreat", 'code' => 'PKG-CR', 'price' => 2900, 'minutes' => 120, 'points' => 35,
            'description' => 'A 90-minute couples massage and foot reflexology for two.',
            'items' => [['Couples Massage', 90, 1], ['Foot Reflexology', 30, 2]]],
    ];

    private const MASSAGE = ['Swedish Massage', 'Deep Tissue Massage', 'Hilot', 'Hot Stone Massage', 'Back and Shoulder Massage', 'Ventosa Cupping', 'Body Scrub'];

    private const ROOMS = [
        ['name' => 'Massage Room 1', 'category' => 'Massage', 'amenities' => ['Dimmable Lighting', 'Sound System', 'Shower'], 'services' => self::MASSAGE,
            'description' => 'Single massage room with a shower.'],
        ['name' => 'Massage Room 2', 'category' => 'Massage', 'amenities' => ['Dimmable Lighting', 'Aromatherapy Diffuser'], 'services' => self::MASSAGE,
            'description' => 'Single massage room with aromatherapy.'],
        ['name' => 'Facial Room', 'category' => 'Facial', 'amenities' => ['Magnifying Lamp', 'Facial Steamer'], 'services' => ['Classic Facial', 'Anti-Aging Facial'],
            'description' => 'Treatment bed with facial equipment.'],
        ['name' => 'Nail and Hair Lounge', 'category' => 'Nails', 'amenities' => ['Pedicure Chairs', 'Hair Wash Station'],
            'services' => ['Classic Manicure', 'Classic Pedicure', 'Gel Manicure', 'Foot Reflexology', 'Hair Spa Treatment'],
            'description' => 'Two pedicure chairs, a manicure table and a hair wash station.'],
        ['name' => 'Couples Suite', 'category' => 'Couples', 'amenities' => ['Two Massage Beds', 'Private Shower', 'Tea Service'],
            'services' => ['Couples Massage', 'Swedish Massage', 'Hot Stone Massage'],
            'description' => 'Private suite for two with side-by-side beds.'],
    ];

    // Per branch: [first, last, gender, role, employment, birth, hire, skills key]
    private const STAFF = [
        'LNG' => [
            ['Maricel', 'Dela Cruz', 'Female', 'manager', 'Full-Time', '1988-05-14', '2024-03-01', null],
            ['Janine', 'Soriano', 'Female', 'frontdesk', 'Full-Time', '1998-09-02', '2024-06-15', null],
            ['Rodel', 'Manalo', 'Male', 'therapist', 'Full-Time', '1992-11-20', '2024-03-15', 'massage'],
            ['Liza', 'Fernandez', 'Female', 'therapist', 'Full-Time', '1995-02-08', '2024-04-01', 'massage'],
            ['Grace', 'Villanueva', 'Female', 'therapist', 'Full-Time', '1997-07-25', '2024-08-10', 'skin'],
            ['Aileen', 'Pascual', 'Female', 'therapist', 'Part-Time', '2000-01-17', '2025-02-01', 'nails'],
        ],
        'MTN' => [
            ['Ramon', 'Aquino', 'Male', 'manager', 'Full-Time', '1987-03-30', '2024-05-01', null],
            ['Kristine', 'Lopez', 'Female', 'frontdesk', 'Full-Time', '1999-12-11', '2024-07-01', null],
            ['Jomar', 'Castillo', 'Male', 'therapist', 'Full-Time', '1993-06-05', '2024-05-15', 'massage'],
            ['Rhea', 'Mercado', 'Female', 'therapist', 'Full-Time', '1996-10-19', '2024-06-01', 'massage'],
            ['Donna', 'Garcia', 'Female', 'therapist', 'Contractual', '1998-04-23', '2025-01-10', 'skin'],
            ['Precious', 'Ramos', 'Female', 'therapist', 'Part-Time', '2001-08-09', '2025-03-01', 'nails'],
        ],
    ];

    private const SKILLS = [
        'massage' => ['Swedish Massage', 'Deep Tissue Massage', 'Hilot', 'Hot Stone Massage', 'Back and Shoulder Massage', 'Ventosa Cupping', 'Couples Massage', 'Foot Reflexology', 'Body Scrub'],
        'skin' => ['Classic Facial', 'Anti-Aging Facial', 'Body Scrub', 'Swedish Massage', 'Hair Spa Treatment'],
        'nails' => ['Classic Manicure', 'Classic Pedicure', 'Gel Manicure', 'Foot Reflexology', 'Hair Spa Treatment'],
    ];

    private User $owner;
    private SpaBusiness $business;
    /** @var array<string, SpaBranch> */
    private array $branches = [];
    /** @var array<string, Service> */
    private array $services = [];

    public function run(): void
    {
        $admin = User::where('role', 'system_administrator')->orderBy('id')->first();
        if (! $admin) {
            $this->command->warn('No system administrator yet — run DatabaseSeeder first.');

            return;
        }
        // Services are adopted from the templates.
        $this->call(ServiceTemplateSeeder::class);

        $this->ownerAndBusiness($admin);
        $this->branches($admin);
        $this->services();
        $this->packages();
        $this->rooms();
        $this->staff();
        $this->settings();
        $this->programs();

        $this->command->info(self::BUSINESS . ' is ready. Logins (password: DEMO_PASSWORD):');
        $this->command->line('  owner   ' . self::OWNER_EMAIL);
        foreach (['lanang', 'matina'] as $b) {
            $this->command->line("  manager {$b}.manager@lotushaven.demo   front desk {$b}.desk@lotushaven.demo");
        }
    }

    private function password(): string
    {
        return (string) env('DEMO_PASSWORD', 'LotusHaven#2026');
    }

    // A service method either returns a resource or a JsonResponse error.
    private function ok(mixed $result, string $what): mixed
    {
        if ($result instanceof JsonResponse && $result->getStatusCode() >= 400) {
            throw new RuntimeException("{$what}: " . ($result->getData(true)['message'] ?? $result->getContent()));
        }

        return $result;
    }

    // ── Owner, business, verification, subscription ──────────────────────

    private function ownerAndBusiness(User $admin): void
    {
        $this->owner = User::where('email', self::OWNER_EMAIL)->where('role', 'business_owner')->first()
            ?? User::create([
                'role' => 'business_owner',
                'username' => 'lotushaven.owner',
                'email' => self::OWNER_EMAIL,
                'password' => $this->password(),
                'first_name' => 'Isabel',
                'last_name' => 'Montemayor',
                'phone_number' => '09171230000',
                'email_verified_at' => now(),
                'account_status' => 'Active',
            ]);

        OwnerIdentityVerification::firstOrCreate(['user_id' => $this->owner->id], [
            'id_type' => 'Philippine National ID',
            'liveness_result' => 'Passed',
            'face_match_result' => 'Passed',
            'status' => 'Verified',
            'verified_by' => $admin->id,
            'verified_at' => now(),
        ]);

        $this->business = SpaBusiness::where('owner_id', $this->owner->id)->first()
            ?? SpaBusiness::create([
                'owner_id' => $this->owner->id,
                'business_name' => self::BUSINESS,
                'legal_name' => 'Lotus Haven Wellness Inc.',
                'spa_type' => 'Day Spa',
                'tagline' => 'Unwind the Davao way',
                'business_email' => 'hello@lotushaven.demo',
                'business_phone' => '09171230000',
                'head_office_address' => 'J.P. Laurel Avenue, Lanang, Davao City',
                'business_description' => 'A Davao day spa offering Filipino hilot, massages, facials and nail care in calm, private rooms.',
                'business_type' => 'Corporation',
                'registration_document_type' => 'SEC',
                'registered_business_name' => 'Lotus Haven Wellness Inc.',
                'authorized_representative_name' => 'Isabel Montemayor',
                'registration_number' => 'CS202612345',
                'verification_status' => 'Verified',
                'operating_status' => 'Active',
                'verified_by' => $admin->id,
                'verified_at' => now(),
            ]);

        $hasSubscription = Subscription::where('spa_business_id', $this->business->id)->where('status', 'Active')->exists();
        if (! $hasSubscription) {
            $plan = SubscriptionPlan::where('is_active', true)->where('reward_access', true)->where('category', 'Enterprise')->first()
                ?? SubscriptionPlan::where('is_active', true)->where('reward_access', true)->first()
                ?? SubscriptionPlan::where('is_active', true)->first();
            if (! $plan) {
                throw new RuntimeException('No active subscription plan — run SubscriptionPlanSeeder first.');
            }
            Subscription::create([
                'spa_business_id' => $this->business->id,
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => 'Monthly',
                'starts_at' => now(),
                'expires_at' => now()->addYear(),
                'status' => 'Active',
            ]);
        }
    }

    // ── Branches and opening hours ────────────────────────────────────────

    private function branches(User $admin): void
    {
        $scheduleService = app(BranchScheduleService::class);

        foreach (self::BRANCHES as $code => $b) {
            $branch = SpaBranch::where('spa_business_id', $this->business->id)->where('code', $code)->first();
            if (! $branch) {
                $branch = $this->ok(app(SpaBranchService::class)->createSpaBranch($this->owner, [
                    'branch_name' => $b['branch_name'],
                    'code' => $code,
                    'email' => $b['email'],
                    'phone_number' => $b['phone_number'],
                    'description' => $b['description'],
                ]), "branch {$code}")->resource;

                // Location, permit and the admin's approval — the steps the
                // owner's wizard and System → Branch Registrations do. The
                // permit document itself is an upload, so it's left out.
                $branch->update([
                    'latitude' => $b['latitude'],
                    'longitude' => $b['longitude'],
                    'formatted_address' => $b['formatted_address'],
                    'permit_number' => $b['permit_number'],
                    'permit_business_name' => 'Lotus Haven Wellness Inc.',
                    'permit_branch_location' => $b['formatted_address'],
                    'permit_issue_date' => now()->startOfYear()->toDateString(),
                    'permit_expiration_date' => now()->endOfYear()->toDateString(),
                    'permit_confirmed' => true,
                    'promo_text' => $b['promo_text'],
                    'listing_visible' => true,
                    'operating_status' => 'Active',
                    'verification_status' => 'Verified',
                    'verified_by' => $admin->id,
                    'verified_at' => now(),
                ]);
            }
            $this->branches[$code] = $branch;

            foreach (self::DAYS as $day) {
                if (BranchSchedule::where('spa_branch_id', $branch->id)->where('day_of_week', $day)->exists()) {
                    continue;
                }
                $sunday = $day === 'Sunday';
                $this->ok($scheduleService->createBranchSchedule($this->owner, [
                    'spa_branch_uuid' => $branch->uuid,
                    'day_of_week' => $day,
                    'opening_time' => $sunday ? '11:00' : '10:00',
                    'closing_time' => $sunday ? '20:00' : '22:00',
                    'is_closed' => false,
                ]), "{$code} {$day} hours");
            }
        }
    }

    // ── Services (from templates), priced at both branches ────────────────

    private function services(): void
    {
        $serviceService = app(ServiceService::class);
        $catalog = collect(ServiceTemplateSeeder::TEMPLATES)->keyBy('name');

        foreach (self::SERVICES as $name) {
            $service = Service::where('spa_business_id', $this->business->id)->where('name', $name)->first();

            if (! $service) {
                $template = Service::with('variants')->where('is_template', true)->whereNull('spa_business_id')->where('name', $name)->first();
                $def = $catalog[$name];
                $variants = $template && $template->variants->isNotEmpty()
                    ? $template->variants->map(fn ($v) => ['duration_minutes' => $v->duration_minutes, 'price' => (float) $v->price, 'loyalty_points' => $v->loyalty_points])->all()
                    : array_map(fn ($v) => ['duration_minutes' => $v[0], 'price' => $v[1], 'loyalty_points' => $v[2]], $def['variants']);

                $service = $this->ok($serviceService->createService($this->owner, array_filter([
                    'name' => $name,
                    'code' => $template?->code ?? $def['code'],
                    'category' => $template?->category ?? $def['category'],
                    'description' => $template?->description ?? $def['description'],
                    'is_active' => true,
                    'source_template_uuid' => $template?->uuid,
                    'variants' => $variants,
                ], fn ($v) => $v !== null)), "service {$name}")->resource;
            }

            $service->load('variants');
            $this->services[$name] = $service;

            foreach ($service->variants as $variant) {
                if ($variant->branchServices()->count() >= count($this->branches)) {
                    continue;
                }
                $isMassage = in_array($name, self::MASSAGE, true);
                $this->ok($serviceService->updateVariantBranches($this->owner, $variant->uuid, [
                    ['branch_uuid' => $this->branches['LNG']->uuid, 'is_available' => true, 'custom_price' => $isMassage ? (float) $variant->price + 100 : null],
                    ['branch_uuid' => $this->branches['MTN']->uuid, 'is_available' => true, 'custom_price' => null],
                ]), "branches for {$name} {$variant->duration_minutes} min");
            }
        }
    }

    private function variantUuid(string $service, int $minutes): string
    {
        $variants = $this->services[$service]->variants;

        return ($variants->firstWhere('duration_minutes', $minutes) ?? $variants->first())->uuid;
    }

    // ── Packages ──────────────────────────────────────────────────────────

    private function packages(): void
    {
        $packageService = app(PackageService::class);

        foreach (self::PACKAGES as $p) {
            $package = Package::where('spa_business_id', $this->business->id)->where('name', $p['name'])->first()
                ?? $this->ok($packageService->createPackage($this->owner, [
                    'name' => $p['name'],
                    'code' => $p['code'],
                    'description' => $p['description'],
                    'duration_minutes' => $p['minutes'],
                    'default_price' => $p['price'],
                    'loyalty_points' => $p['points'],
                    'is_active' => true,
                    'services' => array_map(fn ($i) => ['service_variant_uuid' => $this->variantUuid($i[0], $i[1]), 'quantity' => $i[2]], $p['items']),
                ]), "package {$p['name']}")->resource;

            if ($package->branchPackages()->count() < count($this->branches)) {
                $this->ok($packageService->updatePackageBranches($this->owner, $package->uuid, array_map(
                    fn (SpaBranch $b) => ['branch_uuid' => $b->uuid, 'is_available' => true],
                    array_values($this->branches),
                )), "branches for {$p['name']}");
            }
        }
    }

    // ── Rooms ─────────────────────────────────────────────────────────────

    private function rooms(): void
    {
        $facilityService = app(FacilityService::class);

        foreach ($this->branches as $code => $branch) {
            foreach (self::ROOMS as $room) {
                if (Facility::where('spa_branch_id', $branch->id)->where('name', $room['name'])->exists()) {
                    continue;
                }
                $this->ok($facilityService->createFacility($this->owner, [
                    'spa_branch_uuid' => $branch->uuid,
                    'name' => $room['name'],
                    'description' => $room['description'],
                    'category' => $room['category'],
                    'status' => 'Available',
                    'is_available' => true,
                    'amenities' => $room['amenities'],
                    'service_ids' => array_map(fn ($s) => $this->services[$s]->uuid, $room['services']),
                ]), "{$code} {$room['name']}");
            }
        }
    }

    // ── Staff, schedules, skills, logins ──────────────────────────────────

    private function staff(): void
    {
        $staffService = app(StaffService::class);
        $scheduleService = app(StaffScheduleService::class);
        $accountService = app(AccountService::class);
        $slug = ['LNG' => 'lanang', 'MTN' => 'matina'];

        foreach (self::STAFF as $code => $roster) {
            $branch = $this->branches[$code];

            foreach ($roster as $i => [$first, $last, $gender, $role, $employment, $birth, $hire, $skills]) {
                $staff = Staff::where('spa_branch_id', $branch->id)->where('first_name', $first)->where('last_name', $last)->first();

                if (! $staff) {
                    $staff = $this->ok($staffService->createStaff($this->owner, [
                        'spa_branch_uuid' => $branch->uuid,
                        'first_name' => $first,
                        'last_name' => $last,
                        'gender' => $gender,
                        'role' => $role,
                        'employment_type' => $employment,
                        'birth_date' => $birth,
                        'hire_date' => $hire,
                        'email' => strtolower(str_replace(' ', '', "{$first}.{$last}")) . '@lotushaven.demo',
                        'phone_number' => sprintf('0917%03d%04d', $code === 'LNG' ? 555 : 666, 1000 + $i),
                        'emergency_contact_name' => "Maria {$last}",
                        'emergency_contact_number' => sprintf('0918%03d%04d', $code === 'LNG' ? 555 : 666, 2000 + $i),
                    ]), "staff {$first} {$last}")->resource;

                    // Weekly schedule: one day off each (rotating), therapists
                    // alternate an opening and a closing shift.
                    $late = $role === 'therapist' && $i % 2 === 1;
                    $this->ok($scheduleService->updateSchedule($this->owner, $staff->uuid, [
                        'days' => array_map(fn ($day, $d) => $d === ($i % 6)
                            ? ['day_of_week' => $day, 'is_day_off' => true]
                            : ['day_of_week' => $day, 'is_day_off' => false,
                                'start_time' => $day === 'Sunday' ? '11:00' : ($late ? '13:00' : '10:00'),
                                'end_time' => $day === 'Sunday' ? '20:00' : ($late ? '22:00' : '19:00'),
                                'break_start' => $late ? '17:00' : '14:00',
                                'break_end' => $late ? '18:00' : '15:00'],
                            self::DAYS, array_keys(self::DAYS)),
                    ]), "schedule {$first}");

                    if ($skills) {
                        $this->ok($staffService->updateServices(
                            $this->owner,
                            $staff->uuid,
                            array_map(fn ($s) => $this->services[$s]->uuid, self::SKILLS[$skills]),
                        ), "skills {$first}");
                    }
                }

                if (in_array($role, ['manager', 'frontdesk'], true) && ! $staff->fresh()->user_id) {
                    $username = $slug[$code] . ($role === 'manager' ? '.manager' : '.desk');
                    if (User::where('username', $username)->exists()) {
                        continue;
                    }
                    $this->ok($accountService->createAccount($this->owner, [
                        'staff_uuid' => $staff->uuid,
                        'username' => $username,
                        'email' => "{$username}@lotushaven.demo",
                        'password' => $this->password(),
                    ]), "login {$username}");
                    User::where('username', $username)->update(['first_name' => $first, 'last_name' => $last]);
                }
            }
        }
    }

    // ── Settings ──────────────────────────────────────────────────────────

    private function settings(): void
    {
        $settings = app(BusinessSettingsService::class);

        $this->ok($settings->updateSection($this->owner, 'payments', [
            'accept_cash' => true,
            'accept_gcash' => true,
            'gcash_number' => '09171230000',
            'accept_paymaya' => true,
            'paymaya_number' => '09171230000',
            'accept_card' => true,
        ]), 'payments');

        $this->ok($settings->updateSection($this->owner, 'staff_policy', [
            'commission_enabled' => true,
            'commission_type' => 'percentage',
            'default_commission_rate' => 15,
            'attendance_tracking' => true,
            'require_check_in' => true,
        ]), 'staff policy');

        $this->ok($settings->updateSection($this->owner, 'booking_defaults', [
            'online_booking_enabled' => true,
            'walk_in_enabled' => true,
            'queue_enabled' => true,
        ]), 'booking defaults');
    }

    // ── Customer programs (offered at every branch) ───────────────────────

    private function programs(): void
    {
        $programs = app(CustomerProgramService::class);
        $this->ok($programs->setEnabled($this->owner, true), 'programs switch');

        $defs = [
            ['type' => 'loyalty', 'name' => 'Lotus Points', 'values' => ['pointsPerPeso' => '1', 'pointValue' => '0.5', 'minRedeem' => '300', 'expiryMonths' => '12 months']],
            ['type' => 'voucher', 'name' => 'Welcome Treat', 'values' => ['dealType' => '₱ off', 'value' => '100', 'usableOn' => 'All services', 'validDays' => '3 months'], 'condition' => ['kind' => 'first_visit']],
            ['type' => 'voucher', 'name' => 'Big Spender', 'values' => ['dealType' => '₱ off', 'value' => '200', 'usableOn' => 'All services', 'validDays' => '3 months'], 'condition' => ['kind' => 'min_spend', 'value' => 2000]],
            ['type' => 'voucher', 'name' => 'Every 5th Visit', 'values' => ['dealType' => '% off', 'value' => '15', 'usableOn' => 'All services', 'validDays' => '3 months'], 'condition' => ['kind' => 'visits', 'value' => 5]],
            ['type' => 'voucher', 'name' => 'Points Treat', 'values' => ['dealType' => '% off', 'value' => '10', 'usableOn' => 'All services', 'validDays' => '3 months'], 'condition' => ['kind' => 'points', 'value' => 300]],
            ['type' => 'voucher', 'name' => 'Birthday Massage', 'values' => ['dealType' => '% off', 'value' => '20', 'usableOn' => 'All services', 'validDays' => '1 month'], 'condition' => ['kind' => 'birthday']],
            ['type' => 'voucher', 'name' => 'Monthly Credit', 'values' => ['dealType' => '₱ off', 'value' => '300', 'usableOn' => 'All services', 'validDays' => '1 month'], 'condition' => ['kind' => 'membership_only']],
            ['type' => 'discount', 'name' => 'Senior Citizen / PWD', 'audience' => 'all', 'values' => ['kind' => '%', 'percent' => '20', 'appliesTo' => 'All services', 'eligible' => 'Senior citizens and PWD with a valid ID']],
            ['type' => 'discount', 'name' => 'Member Rate', 'audience' => 'members', 'values' => ['kind' => '%', 'percent' => '10', 'appliesTo' => 'All services', 'eligible' => 'Gold members']],
        ];

        foreach ($defs as $def) {
            $exists = CustomerProgram::where('spa_business_id', $this->business->id)->where('type', $def['type'])
                ->when($def['type'] !== 'loyalty', fn ($q) => $q->where('name', $def['name']))->exists();
            if (! $exists) {
                $this->ok($programs->store($this->owner, $def), "program {$def['name']}");
            }
        }

        $uuid = fn (string $name) => CustomerProgram::where('spa_business_id', $this->business->id)->where('name', $name)->value('uuid');
        if (! CustomerProgram::where('spa_business_id', $this->business->id)->where('name', 'Gold Membership')->exists()) {
            $this->ok($programs->store($this->owner, [
                'type' => 'membership',
                'name' => 'Gold Membership',
                'values' => ['price' => '999', 'billing' => 'Monthly', 'perks' => 'Priority booking and free welcome tea'],
                'includes' => ['voucher_uuids' => [$uuid('Monthly Credit')], 'discount_uuids' => [$uuid('Member Rate')]],
            ]), 'program Gold Membership');
        }
    }
}

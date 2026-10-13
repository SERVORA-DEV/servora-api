<?php

namespace Database\Seeders;

use App\Models\BranchSchedule;
use App\Models\CustomerProgram;
use App\Models\Facility;
use App\Models\Package;
use App\Models\Service;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\User;
use App\Service\Business\BranchScheduleService;
use App\Service\Business\BranchSettingsService;
use App\Service\Business\BusinessSettingsService;
use App\Service\Business\CustomerProgramService;
use App\Service\Business\FacilityService;
use App\Service\Business\PackageService;
use App\Service\Business\ServiceService;
use App\Service\Business\StaffScheduleService;
use App\Service\Business\StaffService;
use Illuminate\Database\Seeder;
use Illuminate\Http\JsonResponse;
use RuntimeException;

// What an EXISTING, verified massage spa needs before it can take its first
// booking, at every one of its active branches: opening hours, a massage and
// wellness menu adopted from the admin's service templates, packages, rooms,
// sample staff with weekly schedules and skills, customer programs (vouchers,
// discounts, a membership), and the two listing items the marketplace asks
// for before clients can book — a business description and three highlights.
//
// Deliberately left out: a loyalty program, add-ons, logins for the sample
// staff (the owner creates those, so the owner chooses the passwords), and
// the business's settings (the defaults already allow walk-ins, bookings,
// the queue and cash).
//
// Unlike DemoBusinessSeeder this creates no owner, business, branch or
// subscription — those must exist. Everything goes through the same service
// classes the web app uses, acting as the owner, and is looked up by name
// first, so running it again only fills in what is missing.
//
//   STARTER_OWNER_EMAIL=owner@example.com php artisan db:seed --class=MassageSpaStarterSeeder
class MassageSpaStarterSeeder extends Seeder
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    // Adopted from ServiceTemplateSeeder by name, at the template's lengths
    // and prices.
    private const SERVICES = [
        'Swedish Massage', 'Deep Tissue Massage', 'Hilot', 'Shiatsu Massage', 'Thai Massage',
        'Hot Stone Massage', 'Back and Shoulder Massage', 'Foot Reflexology', 'Ventosa Cupping',
        'Aromatherapy Session', 'Ear Candling', 'Body Scrub', 'Couples Massage',
    ];

    // [service, minutes, quantity] — each a little under the sum of its parts.
    private const PACKAGES = [
        ['name' => 'Relax & Unwind', 'code' => 'PKG-RU', 'price' => 1050, 'minutes' => 90,
            'description' => 'A 60-minute Swedish massage followed by 30 minutes of foot reflexology.',
            'items' => [['Swedish Massage', 60, 1], ['Foot Reflexology', 30, 1]]],
        ['name' => 'Therapeutic Relief', 'code' => 'PKG-TR', 'price' => 1850, 'minutes' => 135,
            'description' => 'Deep tissue massage, ventosa cupping and a back and shoulder massage for stubborn aches.',
            'items' => [['Deep Tissue Massage', 60, 1], ['Ventosa Cupping', 45, 1], ['Back and Shoulder Massage', 30, 1]]],
        ['name' => "Couple's Retreat", 'code' => 'PKG-CR', 'price' => 2800, 'minutes' => 120,
            'description' => 'A 90-minute couples massage and foot reflexology for two.',
            'items' => [['Couples Massage', 90, 1], ['Foot Reflexology', 30, 2]]],
    ];

    private const TABLE_WORK = [
        'Swedish Massage', 'Deep Tissue Massage', 'Hilot', 'Shiatsu Massage', 'Thai Massage', 'Hot Stone Massage',
        'Back and Shoulder Massage', 'Ventosa Cupping', 'Aromatherapy Session', 'Body Scrub', 'Ear Candling',
    ];

    private const ROOMS = [
        ['name' => 'Massage Room 1', 'category' => 'Massage', 'amenities' => ['Dimmable Lighting', 'Sound System'], 'services' => self::TABLE_WORK,
            'description' => 'Single massage room.'],
        ['name' => 'Massage Room 2', 'category' => 'Massage', 'amenities' => ['Dimmable Lighting', 'Aromatherapy Diffuser'], 'services' => self::TABLE_WORK,
            'description' => 'Single massage room with aromatherapy.'],
        ['name' => 'Massage Room 3', 'category' => 'Massage', 'amenities' => ['Dimmable Lighting', 'Shower'], 'services' => self::TABLE_WORK,
            'description' => 'Single massage room with a shower, for scrubs and hot stone.'],
        ['name' => 'Couples Suite', 'category' => 'Couples', 'amenities' => ['Two Massage Beds', 'Private Shower', 'Tea Service'],
            'services' => ['Couples Massage', 'Swedish Massage', 'Hot Stone Massage', 'Aromatherapy Session'],
            'description' => 'Private suite for two with side-by-side beds.'],
        ['name' => 'Reflexology Lounge', 'category' => 'Wellness', 'amenities' => ['Recliner Chairs', 'Foot Bath'],
            'services' => ['Foot Reflexology', 'Back and Shoulder Massage', 'Ear Candling'],
            'description' => 'Recliners for foot reflexology and quick back and shoulder work.'],
    ];

    // Listing highlights, each one true of what this seeder sets up: the
    // rooms, the Couple's Retreat package, walk-ins left on, the senior/PWD
    // discount.
    private const HIGHLIGHTS = ['Private Rooms', 'Couples Package', 'Walk-ins Welcome', 'Senior Discount'];

    private const DESCRIPTION = 'Therapeutic massage and wellness: Swedish, deep tissue, hilot, shiatsu and Thai massage, '
        . 'hot stone, ventosa cupping and foot reflexology, in private rooms.';

    // Sample people, one roster per branch (the first roster again for a
    // third branch, and so on): [first, last, gender, role, employment].
    // Therapists are split between men and women so a client's preferred
    // gender can be met at either branch.
    private const ROSTERS = [
        [
            ['Carmela', 'Bautista', 'Female', 'manager', 'Full-Time'],
            ['Jessa', 'Navarro', 'Female', 'frontdesk', 'Full-Time'],
            ['Marlon', 'Dizon', 'Male', 'therapist', 'Full-Time'],
            ['Rosalie', 'Tolentino', 'Female', 'therapist', 'Full-Time'],
            ['Arnel', 'Sarmiento', 'Male', 'therapist', 'Full-Time'],
            ['Divina', 'Cabrera', 'Female', 'therapist', 'Part-Time'],
        ],
        [
            ['Noel', 'Evangelista', 'Male', 'manager', 'Full-Time'],
            ['Charmaine', 'Yap', 'Female', 'frontdesk', 'Full-Time'],
            ['Jerome', 'Agustin', 'Male', 'therapist', 'Full-Time'],
            ['Lourdes', 'Salazar', 'Female', 'therapist', 'Full-Time'],
            ['Dennis', 'Morales', 'Male', 'therapist', 'Full-Time'],
            ['Maribel', 'Santiago', 'Female', 'therapist', 'Part-Time'],
        ],
    ];

    private User $owner;
    private SpaBusiness $business;
    /** @var SpaBranch[] */
    private array $branches = [];
    /** @var array<string, Service> */
    private array $services = [];
    /** @var callable(string): void */
    private $say;
    /** @var array<string, int> what this run added */
    private array $added = [];

    public function run(): void
    {
        $email = (string) env('STARTER_OWNER_EMAIL', '');
        if ($email === '') {
            $this->command?->warn('Set STARTER_OWNER_EMAIL to the business owner\'s email.');

            return;
        }
        $this->seedFor($email, fn (string $line) => $this->command?->line($line));
    }

    /**
     * Fills in the starter data for the business this owner has.
     *
     * @return array<string, int> how many of each thing this run added
     */
    public function seedFor(string $ownerEmail, ?callable $say = null): array
    {
        $this->say = $say ?? static function (string $line): void {};
        $this->added = [];

        $owner = User::where('email', $ownerEmail)->where('role', 'business_owner')->first();
        if (! $owner) {
            throw new RuntimeException("No business owner account for {$ownerEmail}.");
        }
        $business = SpaBusiness::where('owner_id', $owner->id)->first();
        if (! $business) {
            throw new RuntimeException("{$ownerEmail} has no business yet.");
        }
        $this->owner = $owner;
        $this->business = $business;
        $this->branches = SpaBranch::where('spa_business_id', $business->id)->where('operating_status', 'Active')->orderBy('id')->get()->all();
        if (! $this->branches) {
            throw new RuntimeException("{$business->business_name} has no active branch.");
        }

        // Services are adopted from the admin's templates as they stand. The
        // template seeder is NOT run here: on a live system that would put
        // back templates the administrator has since changed or removed.
        $this->hours();
        $this->services();
        $this->packages();
        $this->rooms();
        $this->staff();
        $this->programs();
        $this->listing();

        ($this->say)($business->business_name . ' — added: ' . ($this->added
            ? implode(', ', array_map(fn ($k, $n) => "{$n} {$k}", array_keys($this->added), $this->added))
            : 'nothing (it was all there already)'));

        return $this->added;
    }

    private function count(string $what, int $n = 1): void
    {
        $this->added[$what] = ($this->added[$what] ?? 0) + $n;
    }

    // A service method either returns a resource or a JsonResponse error.
    private function ok(mixed $result, string $what): mixed
    {
        if ($result instanceof JsonResponse && $result->getStatusCode() >= 400) {
            throw new RuntimeException("{$what}: " . ($result->getData(true)['message'] ?? $result->getContent()));
        }

        return $result;
    }

    // ── Opening hours: 10 AM – 10 PM, Sundays 11 AM – 8 PM ───────────────

    private function hours(): void
    {
        $scheduleService = app(BranchScheduleService::class);

        foreach ($this->branches as $branch) {
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
                ]), "{$branch->branch_name} {$day} hours");
                $this->count('opening days');
            }
        }
    }

    // ── Services (from templates), offered at every branch ───────────────

    private function services(): void
    {
        $serviceService = app(ServiceService::class);
        $catalog = collect(ServiceTemplateSeeder::TEMPLATES)->keyBy('name');

        foreach (self::SERVICES as $name) {
            $service = Service::where('spa_business_id', $this->business->id)->where('name', $name)->first();

            if (! $service) {
                $template = Service::with('variants')->where('is_template', true)->whereNull('spa_business_id')->where('name', $name)->first();
                $def = $catalog[$name] ?? null;
                if (! $template && ! $def) {
                    throw new RuntimeException("No service template called {$name}.");
                }
                // No loyalty points: this business runs no loyalty program.
                $variants = $template && $template->variants->isNotEmpty()
                    ? $template->variants->map(fn ($v) => ['duration_minutes' => $v->duration_minutes, 'price' => (float) $v->price, 'loyalty_points' => 0])->all()
                    : array_map(fn ($v) => ['duration_minutes' => $v[0], 'price' => $v[1], 'loyalty_points' => 0], $def['variants']);

                $service = $this->ok($serviceService->createService($this->owner, array_filter([
                    'name' => $name,
                    'code' => $template?->code ?? $def['code'],
                    'category' => $template?->category ?? $def['category'],
                    'description' => $template?->description ?? $def['description'],
                    'is_active' => true,
                    'source_template_uuid' => $template?->uuid,
                    'variants' => $variants,
                ], fn ($v) => $v !== null)), "service {$name}")->resource;
                $this->count('services');
            }

            $service->load('variants');
            $this->services[$name] = $service;

            foreach ($service->variants as $variant) {
                if ($variant->branchServices()->count() >= count($this->branches)) {
                    continue;
                }
                $this->ok($serviceService->updateVariantBranches($this->owner, $variant->uuid, array_map(
                    fn (SpaBranch $b) => ['branch_uuid' => $b->uuid, 'is_available' => true, 'custom_price' => null],
                    $this->branches,
                )), "branches for {$name} {$variant->duration_minutes} min");
            }
        }
    }

    private function variantUuid(string $service, int $minutes): string
    {
        $variants = $this->services[$service]->variants;

        return ($variants->firstWhere('duration_minutes', $minutes) ?? $variants->first())->uuid;
    }

    // ── Packages (no add-ons) ────────────────────────────────────────────

    private function packages(): void
    {
        $packageService = app(PackageService::class);

        foreach (self::PACKAGES as $p) {
            $package = Package::where('spa_business_id', $this->business->id)->where('name', $p['name'])->first();
            if (! $package) {
                $package = $this->ok($packageService->createPackage($this->owner, [
                    'name' => $p['name'],
                    'code' => $p['code'],
                    'description' => $p['description'],
                    'duration_minutes' => $p['minutes'],
                    'default_price' => $p['price'],
                    'loyalty_points' => 0,
                    'is_active' => true,
                    'services' => array_map(fn ($i) => ['service_variant_uuid' => $this->variantUuid($i[0], $i[1]), 'quantity' => $i[2]], $p['items']),
                ]), "package {$p['name']}")->resource;
                $this->count('packages');
            }

            if ($package->branchPackages()->count() < count($this->branches)) {
                $this->ok($packageService->updatePackageBranches($this->owner, $package->uuid, array_map(
                    fn (SpaBranch $b) => ['branch_uuid' => $b->uuid, 'is_available' => true],
                    $this->branches,
                )), "branches for {$p['name']}");
            }
        }
    }

    // ── Rooms ────────────────────────────────────────────────────────────

    private function rooms(): void
    {
        $facilityService = app(FacilityService::class);

        foreach ($this->branches as $branch) {
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
                ]), "{$branch->branch_name} {$room['name']}");
                $this->count('rooms');
            }
        }
    }

    // ── Sample staff, their weekly schedules and what they can perform ───

    private function staff(): void
    {
        $staffService = app(StaffService::class);
        $scheduleService = app(StaffScheduleService::class);
        $everything = array_map(fn (Service $s) => $s->uuid, array_values($this->services));

        foreach (array_values($this->branches) as $b => $branch) {
            // A branch that already has people is left to its own roster.
            if (Staff::where('spa_branch_id', $branch->id)->exists()) {
                continue;
            }

            foreach (self::ROSTERS[$b % count(self::ROSTERS)] as $i => [$first, $last, $gender, $role, $employment]) {
                $staff = $this->ok($staffService->createStaff($this->owner, array_filter([
                    'spa_branch_uuid' => $branch->uuid,
                    'first_name' => $first,
                    'last_name' => $last,
                    'gender' => $gender,
                    'role' => $role,
                    'employment_type' => $employment,
                    'hire_date' => now()->toDateString(),
                    'specialties' => $role === 'therapist' ? ['Therapist'] : null,
                ])), "staff {$first} {$last}")->resource;
                $this->count('staff');

                // One day off each, Monday to Saturday in turn. The manager and
                // every other therapist take the closing shift, so the branch
                // is covered from opening to closing.
                $late = $role === 'manager' || ($role === 'therapist' && $i % 2 === 1);
                $this->ok($scheduleService->updateSchedule($this->owner, $staff->uuid, [
                    'days' => array_map(fn ($day, $d) => $d === ($i % 6)
                        ? ['day_of_week' => $day, 'is_day_off' => true]
                        : ['day_of_week' => $day, 'is_day_off' => false,
                            'start_time' => $day === 'Sunday' ? '11:00' : ($late ? '13:00' : '10:00'),
                            'end_time' => $day === 'Sunday' ? '20:00' : ($late ? '22:00' : '19:00')],
                        self::DAYS, array_keys(self::DAYS)),
                ]), "schedule {$first}");

                if ($role === 'therapist') {
                    $this->ok($staffService->updateServices($this->owner, $staff->uuid, $everything), "skills {$first}");
                }
            }
        }
    }

    // ── Customer programs: vouchers, discounts and a membership ──────────
    // No loyalty program, so no voucher that is earned with points either.

    private function programs(): void
    {
        $programs = app(CustomerProgramService::class);
        $this->ok($programs->setEnabled($this->owner, true), 'programs switch');

        $defs = [
            ['type' => 'voucher', 'name' => 'Welcome Treat', 'values' => ['dealType' => '₱ off', 'value' => '100', 'usableOn' => 'All services', 'validDays' => '3 months'], 'condition' => ['kind' => 'first_visit']],
            ['type' => 'voucher', 'name' => 'Big Spender', 'values' => ['dealType' => '₱ off', 'value' => '200', 'usableOn' => 'All services', 'validDays' => '3 months'], 'condition' => ['kind' => 'min_spend', 'value' => 2000]],
            ['type' => 'voucher', 'name' => 'Every 5th Visit', 'values' => ['dealType' => '% off', 'value' => '15', 'usableOn' => 'All services', 'validDays' => '3 months'], 'condition' => ['kind' => 'visits', 'value' => 5]],
            ['type' => 'voucher', 'name' => 'Birthday Massage', 'values' => ['dealType' => '% off', 'value' => '20', 'usableOn' => 'All services', 'validDays' => '1 month'], 'condition' => ['kind' => 'birthday']],
            ['type' => 'voucher', 'name' => 'Monthly Credit', 'values' => ['dealType' => '₱ off', 'value' => '300', 'usableOn' => 'All services', 'validDays' => '1 month'], 'condition' => ['kind' => 'membership_only']],
            ['type' => 'discount', 'name' => 'Senior Citizen / PWD', 'audience' => 'all', 'values' => ['kind' => '%', 'percent' => '20', 'appliesTo' => 'All services', 'eligible' => 'Senior citizens and PWD with a valid ID']],
            ['type' => 'discount', 'name' => 'Member Rate', 'audience' => 'members', 'values' => ['kind' => '%', 'percent' => '10', 'appliesTo' => 'All services', 'eligible' => 'Gold members']],
        ];

        $has = fn (string $name) => CustomerProgram::where('spa_business_id', $this->business->id)->where('name', $name)->exists();

        foreach ($defs as $def) {
            if (! $has($def['name'])) {
                $this->ok($programs->store($this->owner, $def), "program {$def['name']}");
                $this->count($def['type'] . 's');
            }
        }

        $uuid = fn (string $name) => CustomerProgram::where('spa_business_id', $this->business->id)->where('name', $name)->value('uuid');
        if (! $has('Gold Membership')) {
            $this->ok($programs->store($this->owner, [
                'type' => 'membership',
                'name' => 'Gold Membership',
                'values' => ['price' => '999', 'billing' => 'Monthly', 'perks' => 'Priority booking and free welcome tea'],
                'includes' => ['voucher_uuids' => [$uuid('Monthly Credit')], 'discount_uuids' => [$uuid('Member Rate')]],
            ]), 'program Gold Membership');
            $this->count('memberships');
        }
    }

    // ── What the marketplace asks for before clients can book ────────────
    // Only where the owner has not written their own.

    private function listing(): void
    {
        if (trim((string) $this->business->business_description) === '') {
            $this->ok(app(BusinessSettingsService::class)->updateIdentity(
                $this->owner, ['business_description' => self::DESCRIPTION], null, false,
            ), 'business description');
            $this->count('business description');
        }

        $settings = app(BranchSettingsService::class);
        foreach ($this->branches as $branch) {
            if (count($branch->highlights ?? []) >= 3) {
                continue;
            }
            $highlights = array_values(array_unique(array_merge($branch->highlights ?? [], self::HIGHLIGHTS)));
            $this->ok($settings->updateMarketplace($this->owner, $branch->uuid, ['highlights' => $highlights]), "{$branch->branch_name} highlights");
            $this->count('branch highlight sets');
        }
    }
}

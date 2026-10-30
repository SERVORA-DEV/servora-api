<?php

namespace App\Service\Business;

use App\Http\Resources\BranchScheduleResource;
use App\Http\Resources\LookupFacilityResource;
use App\Http\Resources\LookupPackageResource;
use App\Http\Resources\LookupServiceResource;
use App\Http\Resources\LookupTherapistResource;
use App\Http\Resources\StaffScheduleResource;
use App\Models\CustomerProgram;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\Staff;
use App\Models\User;
use App\Repository\Business\BranchPackageRepository;
use App\Repository\Business\BranchScheduleRepository;
use App\Repository\Business\BranchServiceRepository;
use App\Repository\Business\FacilityRepository;
use App\Repository\Business\StaffRepository;
use App\Repository\SpaBusinessRepository;
use App\Service\Client\BookingPolicy;
use App\Support\ProgramChannel;
use App\Support\ServicePromotions;
use App\Support\VoucherTarget;

// Narrow, read-only projections backing the front-office
// therapist/room/service pickers. front_officer has no
// staff_view/facility_view/service_view permission for the full CRUD
// resources, so these exist specifically to avoid widening that access —
// see routes/api.php's front_officer-inclusive group.
class FrontOfficeLookupService
{
    private StaffRepository $staffRepository;
    private FacilityRepository $facilityRepository;
    private BranchServiceRepository $branchServiceRepository;
    private BranchPackageRepository $branchPackageRepository;
    private SpaBusinessRepository $spaBusinessRepository;
    private BranchScheduleRepository $branchScheduleRepository;
    private AppointmentAvailabilityService $availabilityService;
    private TherapistQueueCalculator $therapistQueueCalculator;

    public function __construct(
        StaffRepository $staffRepository,
        FacilityRepository $facilityRepository,
        BranchServiceRepository $branchServiceRepository,
        BranchPackageRepository $branchPackageRepository,
        SpaBusinessRepository $spaBusinessRepository,
        BranchScheduleRepository $branchScheduleRepository,
        AppointmentAvailabilityService $availabilityService,
        TherapistQueueCalculator $therapistQueueCalculator,
    ) {
        $this->staffRepository = $staffRepository;
        $this->facilityRepository = $facilityRepository;
        $this->branchServiceRepository = $branchServiceRepository;
        $this->branchPackageRepository = $branchPackageRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->branchScheduleRepository = $branchScheduleRepository;
        $this->availabilityService = $availabilityService;
        $this->therapistQueueCalculator = $therapistQueueCalculator;
    }

    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchIdsForUser($user);
    }

    // The branch(es) this front-office user works at — just uuid + name, so
    // the front desk can resolve "my branch" without the owner/manager-only
    // GET /business/branch. A front officer gets their one branch; an owner
    // viewing the front desk gets every branch of their business.
    public function branches(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)
            ->map(fn (SpaBranch $branch) => ['id' => $branch->uuid, 'name' => $branch->branch_name])
            ->values()
            ->all();
    }

    public function therapists(User $user)
    {
        return LookupTherapistResource::collection($this->staffRepository->listActiveTherapistsForBranches($this->branchIds($user)));
    }

    // A single therapist's own read-only profile — deliberately narrow, same
    // "not the full StaffResource" philosophy as therapists() above. Live
    // busy/available status is NOT included here: no dedicated availability
    // endpoint exists, so the frontend derives that itself from the already-
    // loaded appointments list, same as AvailabilityView.vue already does.
    public function therapist(User $user, string $uuid)
    {
        $staff = Staff::where('uuid', $uuid)
            ->whereIn('spa_branch_id', $this->branchIds($user))
            ->where('role', 'therapist')
            ->where('status', 'active')
            ->with(['branch', 'schedules', 'qualifiedServices'])
            ->firstOrFail();

        return [
            'uuid' => $staff->uuid,
            'name' => trim("{$staff->first_name} {$staff->last_name}"),
            'role' => $staff->role,
            // What they do, main one first (App\Support\StaffSpecialty).
            'specialties' => $staff->specialtyList(),
            'branch_name' => $staff->branch?->branch_name,
            'schedule' => StaffScheduleResource::collection($staff->schedules),
            'qualified_services' => $staff->qualifiedServices->map(fn ($service) => [
                'uuid' => $service->uuid,
                'name' => $service->name,
            ]),
        ];
    }

    // The branch's therapist rotation for today — who is next in line to take
    // a client. Shares TherapistQueueCalculator with
    // AppointmentService::therapistOptions(), so the queue board and the
    // assignment picker can never disagree about turn order.
    //
    // `is_next_up` here is simply the first free therapist on the floor: with
    // no service in context there's no qualification to narrow it by, unlike
    // the picker's service-aware version.
    //
    // Same auto-resolved single-branch assumption as schedule()/busyTimes()
    // below — this lookup has no per-branch selector.
    public function therapistQueue(User $user, ?string $date = null)
    {
        $branchIds = $this->branchIds($user);
        $branchId = $branchIds[0] ?? null;

        if (! $branchId) {
            return response()->json(['message' => 'No branch found for this account.'], 422);
        }

        $date = $date ?: now()->format('Y-m-d');
        $rows = $this->therapistQueueCalculator->forBranch($branchId, $date);
        $nextUpUuid = $this->therapistQueueCalculator->nextUp($rows)['uuid'] ?? null;

        return [
            'data' => $rows->map(fn (array $row) => [
                'uuid' => $row['uuid'],
                'name' => $row['name'],
                'specialties' => $row['specialties'] ?? [],
                'position' => $row['position'],
                'in_queue' => $row['in_queue'],
                'rotation_status' => $row['rotation_status'],
                'checked_in_at' => $row['checked_in_at']?->format('H:i'),
                'last_completed_at' => $row['last_completed_at']?->format('H:i'),
                'is_next_up' => $row['uuid'] === $nextUpUuid,
                'earned_today' => $row['earned_today'],
                'earned_period' => $row['earned_period'],
            ])->values(),
        ];
    }

    // Optional filters back the appointment room picker: only rooms that
    // support the given service, and — when a date/time are also supplied —
    // only rooms actually free during that window (a hard check, unlike the
    // advisory therapist-suggestion equivalent; see
    // AppointmentAvailabilityService::isFacilityAvailable()).
    // $excludeAppointmentUuid: the appointment the rooms are being picked
    // for — its own bookings don't make a room "taken" (a second service in
    // the same visit can reuse the room of the first).
    public function facilities(User $user, ?string $serviceVariantUuid = null, ?string $date = null, ?string $time = null, ?string $excludeAppointmentUuid = null)
    {
        $variant = $serviceVariantUuid ? ServiceVariant::where('uuid', $serviceVariantUuid)->first() : null;
        $branchIds = $this->branchIds($user);
        // The whole room list (no service, no time asked): the floor, which shows rooms under maintenance too.
        $rooms = $this->facilityRepository->listAvailableForBranches($branchIds, $variant?->service_id, withMaintenance: ! $variant && ! $date && ! $time);

        if ($date && $time) {
            $duration = $variant?->duration_minutes ?? 30;
            $excludeId = $excludeAppointmentUuid
                ? \App\Models\Appointment::where('uuid', $excludeAppointmentUuid)->whereIn('spa_branch_id', $branchIds)->value('id')
                : null;
            $rooms = $rooms->filter(fn ($room) => $this->availabilityService->isFacilityAvailable($room->id, $date, $time, $duration, excludeAppointmentId: $excludeId)['ok'])->values();
        }

        return LookupFacilityResource::collection($rooms);
    }

    public function services(User $user)
    {
        $branchIds = $this->branchIds($user);
        $rows = $this->branchServiceRepository->listBookableForBranches($branchIds);

        // Each service carries the vouchers and discounts that cover it (a small mark on the
        // menu; the Programs page has the details).
        $promos = $this->promotions($user, $branchIds);
        foreach ($rows as $row) {
            $service = $row->serviceVariant?->service;
            $row->setAttribute('promotions', ServicePromotions::forService($promos, $service?->uuid, $service?->category, $service?->name));
        }

        return LookupServiceResource::collection($rows);
    }

    /**
     * The active vouchers and discounts offered at these branches at the front desk, as plain
     * arrays for ServicePromotions. Empty when customer programs are switched off or not in the plan.
     *
     * @param  array<int, int>  $branchIds
     * @return array<int, array<string, mixed>>
     */
    private function promotions(User $user, array $branchIds): array
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business || ! app(ProgramRewardService::class)->enabledFor($business->id)) {
            return [];
        }

        $programs = app(CustomerProgramService::class);

        return CustomerProgram::where('spa_business_id', $business->id)
            ->where('is_active', true)
            ->whereIn('type', ['voucher', 'discount'])
            ->whereHas('branches', fn ($q) => $q->whereIn('spa_branches.id', $branchIds))
            ->orderBy('created_at')
            ->get()
            ->filter(fn (CustomerProgram $p) => ProgramChannel::allows(ProgramChannel::of($p), ProgramChannel::DESK))
            ->map(function (CustomerProgram $p) use ($programs) {
                $members = $p->audience === 'members';

                if ($p->type === 'discount') {
                    return ['type' => 'discount', 'name' => $p->name, 'label' => $programs->discountText($p), 'applies_to' => (string) $p->value('appliesTo', 'All services'), 'members_only' => $members];
                }

                $target = VoucherTarget::of($p->values, 'usable');
                $freeNamed = $p->value('dealType') === 'Free service' && VoucherTarget::isAll($target) ? (string) $p->value('usableOn', '') : '';

                return [
                    'type' => 'voucher', 'name' => $p->name, 'label' => $programs->dealText($p), 'target' => $target,
                    'free_named' => preg_match('/^(all|any)\b/i', $freeNamed) ? '' : $freeNamed, 'members_only' => $members,
                ];
            })
            ->values()
            ->all();
    }

    // The business's add-ons that are switched on — what the visit page
    // offers on a service's card, filtered there to the ones available for
    // that service (App\Support\ServiceAddon). None until the migration has run.
    public function addons(User $user)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business || ! \App\Models\Addon::ready()) {
            return \App\Http\Resources\AddonResource::collection(collect());
        }

        return \App\Http\Resources\AddonResource::collection(
            app(\App\Repository\Business\AddonRepository::class)->listForBusiness($business->id, activeOnly: true)
        );
    }

    // Empty when the plan doesn't include packages, so the front desk is
    // never offered one it can't add.
    public function packages(User $user)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business || ! \App\Support\AppCache::planAllows($business->id, 'package_access')) {
            return LookupPackageResource::collection(collect());
        }

        return LookupPackageResource::collection($this->branchPackageRepository->listBookableForBranches($this->branchIds($user)));
    }

    // Backs the appointment modal's time-slot filtering. front_officer has
    // no branch_view/branch-schedule permission for the owner-only
    // /business/branch-schedule resource — this returns the caller's own
    // (auto-resolved) branch's week instead, same "exactly one accessible
    // branch, no picker" assumption AppointmentService::createAppointment
    // makes. Only the first branch is used even for an owner account, since
    // this lookup has no per-branch selector.
    // This branch's scanner link — the page a member of staff opens on their
    // own phone to start a home visit by scanning the client's QR
    // (App\Support\HomeVisitStart, ScannerController). The link carries a
    // secret of the branch's own, made the first time it is asked for;
    // $replace makes a new one, which cuts the old link off.
    public function scannerLink(User $user, bool $replace = false)
    {
        $branchId = $this->branchIds($user)[0] ?? null;
        $branch = $branchId ? SpaBranch::find($branchId) : null;

        if (! $branch) {
            return response()->json(['message' => 'No branch found for this account.'], 422);
        }
        if (! \App\Models\Appointment::hasHomeStart()) {
            return response()->json(['data' => ['ready' => false, 'token' => null, 'branch_name' => $branch->branch_name, 'home_service_enabled' => false]]);
        }

        if ($replace || ! $branch->scanner_token) {
            $branch->forceFill(['scanner_token' => \Illuminate\Support\Str::random(40)])->save();
        }

        return response()->json(['data' => [
            'ready' => true,
            'token' => $branch->scanner_token,
            'branch_name' => $branch->branch_name,
            // Off: the link works, but there are no home visits to start.
            'home_service_enabled' => (bool) HomeServiceRules::offer($branch)['enabled'],
        ]]);
    }

    public function schedule(User $user)
    {
        $branchIds = $this->branchIds($user);
        $branchId = $branchIds[0] ?? null;

        if (! $branchId) {
            return response()->json(['message' => 'No branch found for this account.'], 422);
        }

        $branch = SpaBranch::find($branchId);

        return BranchScheduleResource::collection($this->branchScheduleRepository->allForBranch($branchId))
            ->additional([
                // The owner's (or this branch's) rules the desk is held to,
                // so the screens can say so before asking — the API decides
                // (App\Support\FrontDeskPolicy, PolicyGate).
                'policy' => $branch ? array_intersect_key(BookingPolicy::for($branch), array_flip([
                    'booking_lead_time_minutes', 'max_advance_booking_days',
                    'max_services_per_booking', 'max_bookings_per_hour', 'max_service_minutes_per_booking', 'allow_booking_overlap', 'cancellation_window_hours',
                    'queue_enabled', 'max_queue_size', 'walk_ins_first_priority',
                    'allow_service_extension', 'no_show_policy', 'no_show_suspend_after', 'reservation_grace_minutes',
                ])) + ['can_override' => app(PolicyGate::class)->mayOverride($user)] : null,
                // For the modal's Home service option: whether it's offered,
                // the fee and area, and the branch's own pin to measure from.
                'home_service' => $branch ? \App\Service\Business\HomeServiceRules::offer($branch) : ['enabled' => false, 'fee' => 0.0, 'radius_km' => 0, 'min_minutes' => 0],
                'branch_pin' => $branch && $branch->latitude !== null && $branch->longitude !== null
                    ? ['lat' => (float) $branch->latitude, 'lng' => (float) $branch->longitude]
                    : null,
                // What exists yet (each arrives with its migration), so
                // the screens ask for a client's gender and show start
                // codes only once the API keeps them.
                'features' => [
                    'client_profile' => \App\Models\Client::hasProfile(),
                    'home_start' => \App\Models\Appointment::hasHomeStart(),
                ],
                // Temporary closures still to come, so the modal can warn
                // about a time inside one before it is submitted.
                'closures' => \App\Support\BranchClosures::upcoming($branchId)
                    ->map(fn ($closure) => \App\Support\BranchClosures::present($closure))->values(),
            ]);
    }

    // Backs the appointment modal's "grey out already-booked times" — same
    // auto-resolved single-branch assumption as schedule() above.
    public function busyTimes(User $user, string $date)
    {
        $branchIds = $this->branchIds($user);
        $branchId = $branchIds[0] ?? null;

        if (! $branchId) {
            return response()->json(['message' => 'No branch found for this account.'], 422);
        }

        return ['data' => $this->availabilityService->busyWindowsForBranch($branchId, $date)];
    }
}

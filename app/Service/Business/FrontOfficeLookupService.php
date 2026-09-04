<?php

namespace App\Service\Business;

use App\Http\Resources\BranchScheduleResource;
use App\Http\Resources\LookupFacilityResource;
use App\Http\Resources\LookupPackageResource;
use App\Http\Resources\LookupServiceResource;
use App\Http\Resources\LookupTherapistResource;
use App\Http\Resources\StaffScheduleResource;
use App\Models\ServiceVariant;
use App\Models\Staff;
use App\Models\User;
use App\Repository\Business\BranchPackageRepository;
use App\Repository\Business\BranchScheduleRepository;
use App\Repository\Business\BranchServiceRepository;
use App\Repository\Business\FacilityRepository;
use App\Repository\Business\StaffRepository;
use App\Repository\SpaBusinessRepository;

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

    public function __construct(
        StaffRepository $staffRepository,
        FacilityRepository $facilityRepository,
        BranchServiceRepository $branchServiceRepository,
        BranchPackageRepository $branchPackageRepository,
        SpaBusinessRepository $spaBusinessRepository,
        BranchScheduleRepository $branchScheduleRepository,
        AppointmentAvailabilityService $availabilityService,
    ) {
        $this->staffRepository = $staffRepository;
        $this->facilityRepository = $facilityRepository;
        $this->branchServiceRepository = $branchServiceRepository;
        $this->branchPackageRepository = $branchPackageRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->branchScheduleRepository = $branchScheduleRepository;
        $this->availabilityService = $availabilityService;
    }

    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
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
            'branch_name' => $staff->branch?->branch_name,
            'schedule' => StaffScheduleResource::collection($staff->schedules),
            'qualified_services' => $staff->qualifiedServices->map(fn ($service) => [
                'uuid' => $service->uuid,
                'name' => $service->name,
            ]),
        ];
    }

    // Optional filters back the appointment room picker: only rooms that
    // support the given service, and — when a date/time are also supplied —
    // only rooms actually free during that window (a hard check, unlike the
    // advisory therapist-suggestion equivalent; see
    // AppointmentAvailabilityService::isFacilityAvailable()).
    public function facilities(User $user, ?string $serviceVariantUuid = null, ?string $date = null, ?string $time = null)
    {
        $variant = $serviceVariantUuid ? ServiceVariant::where('uuid', $serviceVariantUuid)->first() : null;
        $rooms = $this->facilityRepository->listAvailableForBranches($this->branchIds($user), $variant?->service_id);

        if ($date && $time) {
            $duration = $variant?->duration_minutes ?? 30;
            $rooms = $rooms->filter(fn ($room) => $this->availabilityService->isFacilityAvailable($room->id, $date, $time, $duration)['ok'])->values();
        }

        return LookupFacilityResource::collection($rooms);
    }

    public function services(User $user)
    {
        return LookupServiceResource::collection($this->branchServiceRepository->listBookableForBranches($this->branchIds($user)));
    }

    public function packages(User $user)
    {
        return LookupPackageResource::collection($this->branchPackageRepository->listBookableForBranches($this->branchIds($user)));
    }

    // Backs the appointment modal's time-slot filtering. front_officer has
    // no branch_view/branch-schedule permission for the owner-only
    // /business/branch-schedule resource — this returns the caller's own
    // (auto-resolved) branch's week instead, same "exactly one accessible
    // branch, no picker" assumption AppointmentService::createAppointment
    // makes. Only the first branch is used even for an owner account, since
    // this lookup has no per-branch selector.
    public function schedule(User $user)
    {
        $branchIds = $this->branchIds($user);
        $branchId = $branchIds[0] ?? null;

        if (! $branchId) {
            return response()->json(['message' => 'No branch found for this account.'], 422);
        }

        return BranchScheduleResource::collection($this->branchScheduleRepository->allForBranch($branchId));
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

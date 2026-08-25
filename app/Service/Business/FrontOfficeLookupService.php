<?php

namespace App\Service\Business;

use App\Http\Resources\LookupFacilityResource;
use App\Http\Resources\LookupServiceResource;
use App\Http\Resources\LookupTherapistResource;
use App\Models\User;
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
    private SpaBusinessRepository $spaBusinessRepository;

    public function __construct(
        StaffRepository $staffRepository,
        FacilityRepository $facilityRepository,
        BranchServiceRepository $branchServiceRepository,
        SpaBusinessRepository $spaBusinessRepository,
    ) {
        $this->staffRepository = $staffRepository;
        $this->facilityRepository = $facilityRepository;
        $this->branchServiceRepository = $branchServiceRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
    }

    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
    }

    public function therapists(User $user)
    {
        return LookupTherapistResource::collection($this->staffRepository->listActiveTherapistsForBranches($this->branchIds($user)));
    }

    public function facilities(User $user)
    {
        return LookupFacilityResource::collection($this->facilityRepository->listAvailableForBranches($this->branchIds($user)));
    }

    public function services(User $user)
    {
        return LookupServiceResource::collection($this->branchServiceRepository->listBookableForBranches($this->branchIds($user)));
    }
}

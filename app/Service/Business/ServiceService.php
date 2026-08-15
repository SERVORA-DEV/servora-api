<?php

namespace App\Service\Business;

use App\Models\BranchService;
use App\Models\User;
use App\Repository\Business\BranchServiceRepository;
use App\Repository\Business\ServiceRepository;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Http\Resources\ServiceResource;

class ServiceService
{
    private ServiceRepository $serviceRepository;
    private SpaBusinessRepository $spaBusinessRepository;
    private SpaBranchRepository $spaBranchRepository;
    private BranchServiceRepository $branchServiceRepository;

    public function __construct(
        ServiceRepository $serviceRepository,
        SpaBusinessRepository $spaBusinessRepository,
        SpaBranchRepository $spaBranchRepository,
        BranchServiceRepository $branchServiceRepository,
    ) {
        $this->serviceRepository = $serviceRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->spaBranchRepository = $spaBranchRepository;
        $this->branchServiceRepository = $branchServiceRepository;
    }

    // business_owner's branch ids cover the whole business; manager's cover
    // only their one AccountBranch-assigned branch — see
    // SpaBusinessRepository::branchesForUser. Same convention as
    // FacilityService::branchIds().
    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
    }

    public function listServices(User $user, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $collection = $this->serviceRepository->paginateForBusiness($business->id, $this->branchIds($user), $perPage);
        return ServiceResource::collection($collection);
    }

    /**
     * A new service is auto-enabled (BranchService, is_available=true, no
     * price override) at every one of the business's branches — matches
     * NIKA's single-list design (no per-branch nuance in the UI) while still
     * populating the real per-branch table underneath, so a future
     * per-branch toggle has correct data to start from instead of nothing.
     */
    public function createService(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $payload['spa_business_id'] = $business->id;
        $payload['created_by'] = $user->id;
        $payload['is_active'] = $payload['is_active'] ?? true;

        $service = $this->serviceRepository->create($payload);

        foreach ($this->branchIds($user) as $branchId) {
            BranchService::create([
                'spa_branch_id' => $branchId,
                'service_id' => $service->id,
                'is_available' => true,
            ]);
        }

        return new ServiceResource($service);
    }

    public function getService(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $model = $this->serviceRepository->findByUuidForBusiness($uuid, $business->id, $this->branchIds($user));
        return new ServiceResource($model);
    }

    public function updateService(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // 404s if this uuid isn't (or isn't a service of) this business —
        // update($uuid, ...) alone wouldn't scope that check.
        $this->serviceRepository->findByUuidForBusiness($uuid, $business->id);

        $model = $this->serviceRepository->update($uuid, $payload);
        return new ServiceResource($model);
    }

    public function deleteService(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $this->serviceRepository->findByUuidForBusiness($uuid, $business->id);
        $this->serviceRepository->delete($uuid);
        return true;
    }

    // Bulk-replaces this service's branch_services rows — one per submitted
    // branch — rather than a per-row endpoint, since branch_services has no
    // uuid of its own to address and the frontend always edits the full set
    // at once (same "replace wholesale" shape as
    // PackageService::syncPackageServices).
    public function updateServiceBranches(User $user, string $uuid, array $branches)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);
        $service = $this->serviceRepository->findByUuidForBusiness($uuid, $business->id);

        $rows = [];
        foreach ($branches as $branch) {
            // 404s if this uuid isn't one of the caller's own accessible
            // branches — a manager can't smuggle in a row for a branch they
            // don't own even if they send one, same guard
            // FacilityService::createFacility uses for spa_branch_uuid.
            $spaBranch = $this->spaBranchRepository->findByUuidForBranches($branch['branch_uuid'], $branchIds);

            $rows[] = [
                'spa_branch_id' => $spaBranch->id,
                'is_available' => $branch['is_available'],
                'custom_price' => $branch['custom_price'] ?? null,
            ];
        }

        $this->branchServiceRepository->syncForService($service->id, $rows);

        $service = $this->serviceRepository->findByUuidForBusiness($uuid, $business->id, $branchIds);
        return new ServiceResource($service);
    }
}

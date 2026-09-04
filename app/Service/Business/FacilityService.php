<?php

namespace App\Service\Business;

use App\Models\Service;
use App\Models\User;
use App\Repository\Business\FacilityRepository;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Http\Resources\FacilityResource;

class FacilityService
{
    private FacilityRepository $facilityRepository;
    private SpaBranchRepository $spaBranchRepository;
    private SpaBusinessRepository $spaBusinessRepository;

    public function __construct(
        FacilityRepository $facilityRepository,
        SpaBranchRepository $spaBranchRepository,
        SpaBusinessRepository $spaBusinessRepository,
    ) {
        $this->facilityRepository = $facilityRepository;
        $this->spaBranchRepository = $spaBranchRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
    }

    // business_owner's branch ids cover the whole business; manager's cover
    // only their own staff record's branch — see
    // SpaBusinessRepository::branchesForUser. Every method below scopes
    // through this rather than $business->id, same convention as
    // StaffService, so a manager can only ever see/manage rooms at their own
    // branch.
    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
    }

    public function listFacilities(User $user, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $collection = $this->facilityRepository->paginateForBranches($this->branchIds($user), $perPage);
        return FacilityResource::collection($collection);
    }

    /**
     * spa_branch_uuid must be one of this user's own accessible branches —
     * resolved server-side (404s otherwise) rather than trusted from the
     * payload, same guard StaffService uses on staff creation.
     */
    public function createFacility(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branch = $this->spaBranchRepository->findByUuidForBranches($payload['spa_branch_uuid'], $this->branchIds($user));

        $payload['spa_branch_id'] = $branch->id;
        $payload['status'] = $payload['status'] ?? 'Available';
        $serviceUuids = $payload['service_ids'] ?? null;
        unset($payload['spa_branch_uuid'], $payload['service_ids']);

        $model = $this->facilityRepository->create($payload);
        if ($serviceUuids !== null) {
            $model->services()->sync(Service::whereIn('uuid', $serviceUuids)->pluck('id'));
        }

        // A brand-new room can't have any live assignments yet, so
        // is_occupied is left unloaded here (falsy default) rather than
        // paying for a withExists() query that can only ever come back false.
        return new FacilityResource($model->load(['branch', 'services']));
    }

    public function getFacility(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $model = $this->facilityRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        return new FacilityResource($model);
    }

    public function updateFacility(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        // 404s if this uuid isn't (or isn't a room at) one of this user's
        // own accessible branches — update($uuid, ...) alone wouldn't scope
        // that check.
        $this->facilityRepository->findByUuidForBranches($uuid, $branchIds);

        if (! empty($payload['spa_branch_uuid'])) {
            $branch = $this->spaBranchRepository->findByUuidForBranches($payload['spa_branch_uuid'], $branchIds);
            $payload['spa_branch_id'] = $branch->id;
        }
        $serviceUuids = $payload['service_ids'] ?? null;
        unset($payload['spa_branch_uuid'], $payload['service_ids']);

        $model = $this->facilityRepository->update($uuid, $payload);
        if ($serviceUuids !== null) {
            $model->services()->sync(Service::whereIn('uuid', $serviceUuids)->pluck('id'));
        }

        return new FacilityResource($this->facilityRepository->findByUuidForBranches($uuid, $branchIds));
    }

    public function deleteFacility(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $this->facilityRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        $this->facilityRepository->delete($uuid);
        return true;
    }
}

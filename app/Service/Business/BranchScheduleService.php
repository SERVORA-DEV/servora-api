<?php

namespace App\Service\Business;

use App\Models\SpaBranch;
use App\Models\User;
use App\Repository\Business\BranchScheduleRepository;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Http\Resources\BranchScheduleResource;

class BranchScheduleService
{
    private BranchScheduleRepository $branchScheduleRepository;
    private SpaBranchRepository $spaBranchRepository;
    private SpaBusinessRepository $spaBusinessRepository;

    public function __construct(
        BranchScheduleRepository $branchScheduleRepository,
        SpaBranchRepository $spaBranchRepository,
        SpaBusinessRepository $spaBusinessRepository
    ) {
        $this->branchScheduleRepository = $branchScheduleRepository;
        $this->spaBranchRepository = $spaBranchRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
    }

    // Every entry point below needs "is this branch mine?" before touching
    // schedule rows — centralized here so it can't be skipped. Throws
    // (via firstOrFail) the same 404 whether the branch doesn't exist or
    // just isn't accessible to this user, so it can't be used to enumerate
    // other businesses' branch uuids either.
    //
    // Scoped through SpaBusinessRepository::branchesForUser rather than
    // findByOwnerId — a manager (Marketplace Listing's Booking & Policies
    // quick-edit, which reads/writes this same endpoint) has no owner_id of
    // their own and would always 422 "No spa business found" otherwise, same
    // fix FacilityService applies for its branch scoping.
    private function resolveOwnedBranch(User $user, string $spaBranchUuid): SpaBranch
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            abort(422, 'No spa business found for this account.');
        }

        return $this->spaBranchRepository->findByUuidForBranches($spaBranchUuid, $this->branchIds($user));
    }

    // Same ownership guard, but for a schedule row uuid — resolves through
    // the row's branch rather than trusting a bare FK.
    private function resolveOwnedSchedule(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            abort(422, 'No spa business found for this account.');
        }

        return $this->branchScheduleRepository->findByUuidForBranches($uuid, $this->branchIds($user));
    }

    // business_owner's branch ids cover the whole business; manager's cover
    // only their one AccountBranch-assigned branch — see
    // SpaBusinessRepository::branchesForUser, same convention FacilityService
    // uses.
    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
    }

    // Lists one branch's week — spa_branch_uuid is required precisely
    // because there's no unscoped "list everything" use case here; every
    // caller is asking about a specific branch they must own.
    public function listBranchSchedule(User $user, string $spaBranchUuid)
    {
        $branch = $this->resolveOwnedBranch($user, $spaBranchUuid);
        $collection = $this->branchScheduleRepository->allForBranch($branch->id);
        return BranchScheduleResource::collection($collection);
    }

    public function createBranchSchedule(User $user, array $payload)
    {
        $branch = $this->resolveOwnedBranch($user, $payload['spa_branch_uuid']);

        if ($this->branchScheduleRepository->existsForBranchDay($branch->id, $payload['day_of_week'])) {
            return response()->json([
                'message' => $payload['day_of_week'] . ' already has a schedule for this branch — update it instead of creating a new one.',
            ], 422);
        }

        unset($payload['spa_branch_uuid']);
        $payload['spa_branch_id'] = $branch->id;

        $model = $this->branchScheduleRepository->create($payload);
        return new BranchScheduleResource($model);
    }

    public function getBranchSchedule(User $user, string $uuid)
    {
        $model = $this->resolveOwnedSchedule($user, $uuid);
        return new BranchScheduleResource($model);
    }

    public function getBranchScheduleByField(string $field, $value)
    {
        $model = $this->branchScheduleRepository->findByField($field, $value);
        return new BranchScheduleResource($model);
    }

    // day_of_week is deliberately never part of $payload here (see
    // BranchScheduleUpdateRequest, which has no rule for it) — a day's
    // identity shouldn't shift under an update; register a new row instead.
    public function updateBranchSchedule(User $user, string $uuid, array $payload)
    {
        $this->resolveOwnedSchedule($user, $uuid);
        $model = $this->branchScheduleRepository->update($uuid, $payload);
        return new BranchScheduleResource($model);
    }

    public function deleteBranchSchedule(User $user, string $uuid)
    {
        $this->resolveOwnedSchedule($user, $uuid);
        $this->branchScheduleRepository->delete($uuid);
        return true;
    }

    public function restoreBranchSchedule(string $uuid)
    {
        $model = $this->branchScheduleRepository->restore($uuid);
        return new BranchScheduleResource($model);
    }
}

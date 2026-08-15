<?php

namespace App\Service\Business;

use App\Models\User;
use App\Repository\Business\StaffRepository;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Http\Resources\StaffResource;

class StaffService
{
    private StaffRepository $staffRepository;
    private SpaBranchRepository $spaBranchRepository;
    private SpaBusinessRepository $spaBusinessRepository;

    public function __construct(
        StaffRepository $staffRepository,
        SpaBranchRepository $spaBranchRepository,
        SpaBusinessRepository $spaBusinessRepository,
    ) {
        $this->staffRepository = $staffRepository;
        $this->spaBranchRepository = $spaBranchRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
    }

    // business_owner's branch ids cover the whole business; manager's cover
    // only their one AccountBranch-assigned branch — see
    // SpaBusinessRepository::branchesForUser. Every method below scopes
    // through this rather than $business->id, so a manager can only ever
    // list/view/create/update/delete staff at their own branch.
    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
    }

    public function listStaff(User $user, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $collection = $this->staffRepository->paginateForBranches($this->branchIds($user), $perPage);
        return StaffResource::collection($collection);
    }

    /**
     * spa_branch_uuid must be one of this user's own accessible branches —
     * resolved server-side (404s otherwise) rather than trusted from the
     * payload, same guard SpaBranchService uses for spa_business_id on
     * branch creation. For a manager this means they can only ever create
     * staff at their own branch, never another branch in the business.
     */
    public function createStaff(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branch = $this->spaBranchRepository->findByUuidForBranches($payload['spa_branch_uuid'], $this->branchIds($user));

        $payload['spa_branch_id'] = $branch->id;
        $payload['status'] = $payload['status'] ?? 'active';
        unset($payload['spa_branch_uuid']);

        // Auto-assign EMP-0001-style number when none was supplied — StaffRequest
        // already validated a manually-entered number for uniqueness, so this
        // path only runs (and only ever retries) for the generated case: a
        // rare race against another concurrent create on the same branch
        // landing on the same next number.
        $wasSupplied = ! empty($payload['employee_number']);
        $attempts = 0;

        while (true) {
            if (! $wasSupplied) {
                $payload['employee_number'] = $this->staffRepository->nextEmployeeNumber($branch->id);
            }

            try {
                $model = $this->staffRepository->create($payload);
                break;
            } catch (\Illuminate\Database\QueryException $e) {
                $isDuplicateNumber = str_contains($e->getMessage(), 'employee_number');
                if ($wasSupplied || $isDuplicateNumber === false || ++$attempts >= 5) {
                    throw $e;
                }
            }
        }

        return new StaffResource($model->load('branch'));
    }

    public function getStaff(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $model = $this->staffRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        return new StaffResource($model);
    }

    public function updateStaff(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        // 404s if this uuid isn't (or isn't a staff member of) one of this
        // user's own accessible branches — update($uuid, ...) alone wouldn't
        // scope that check.
        $this->staffRepository->findByUuidForBranches($uuid, $branchIds);

        if (! empty($payload['spa_branch_uuid'])) {
            $branch = $this->spaBranchRepository->findByUuidForBranches($payload['spa_branch_uuid'], $branchIds);
            $payload['spa_branch_id'] = $branch->id;
        }
        unset($payload['spa_branch_uuid']);

        $model = $this->staffRepository->update($uuid, $payload);

        return new StaffResource($model);
    }

    public function deleteStaff(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // findByUuidForBranches 404s if this uuid isn't (or isn't a staff
        // member of) one of this user's own accessible branches — delete($uuid)
        // alone wouldn't scope that check.
        $this->staffRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        $this->staffRepository->delete($uuid);
        return true;
    }
}

<?php

namespace App\Service\Business;

use App\Models\User;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Repository\System\AdminUsersRepository;
use App\Repository\AuditLogRepository;
use App\Http\Resources\SpaBranchResource;
use App\Service\NotificationService;
use Illuminate\Http\Request;

class SpaBranchService
{
    private SpaBranchRepository $spaBranchRepository;
    private SpaBusinessRepository $spaBusinessRepository;
    private AdminUsersRepository $adminUsersRepository;
    private AuditLogRepository $auditLogRepository;
    private NotificationService $notificationService;

    public function __construct(
        SpaBranchRepository $spaBranchRepository,
        SpaBusinessRepository $spaBusinessRepository,
        AdminUsersRepository $adminUsersRepository,
        AuditLogRepository $auditLogRepository,
        NotificationService $notificationService
    ) {
        $this->spaBranchRepository = $spaBranchRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->adminUsersRepository = $adminUsersRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->notificationService = $notificationService;
    }

    // Scoped to what this user can see — every branch for business_owner,
    // only their own assigned branch for manager (see
    // SpaBusinessRepository::branchesForUser). paginateForBusiness() would
    // leak every branch in the business to a manager, who's only supposed
    // to manage their one branch.
    public function listSpaBranch(User $user, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $branchIds = $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
        $collection = $this->spaBranchRepository->paginateForBranches($branchIds, $perPage);
        return SpaBranchResource::collection($collection);
    }

    /**
     * spa_business_id always comes from the authenticated owner's own
     * business, never from the request body — otherwise any owner could
     * register a branch under someone else's business by editing the
     * payload. Latitude/longitude are left as whatever the form sent (or
     * null) — picking a location on a map is a separate feature.
     */
    public function createSpaBranch(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $payload['spa_business_id'] = $business->id;

        // A newly created branch isn't registered yet — the owner still has
        // to drop a map pin and submit it for admin review (see
        // submitRegistration() below) before it can go to Pending.
        $payload['verification_status'] = 'Unregistered';
        $payload['operating_status'] = 'Active';

        $model = $this->spaBranchRepository->create($payload);
        return new SpaBranchResource($model);
    }

    // Same manager-vs-owner scoping as listSpaBranch above — a manager can
    // only look up their own branch by uuid, not any branch in the business.
    public function getSpaBranch(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $branchIds = $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
        $model = $this->spaBranchRepository->findByUuidForBranches($uuid, $branchIds);
        return new SpaBranchResource($model);
    }

    public function getSpaBranchByField(string $field, $value)
    {
        $model = $this->spaBranchRepository->findByField($field, $value);
        return new SpaBranchResource($model);
    }

    /**
     * Same ownership guard as create — findByUuidForBusiness() 404s before
     * update() ever runs if this uuid isn't one of this owner's branches, so
     * an owner can't edit (or reactivate/deactivate) someone else's branch
     * just by knowing its uuid.
     */
    public function updateSpaBranch(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $this->spaBranchRepository->findByUuidForBusiness($uuid, $business->id);

        $model = $this->spaBranchRepository->update($uuid, $payload);
        return new SpaBranchResource($model);
    }

    public function deleteSpaBranch(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $this->spaBranchRepository->findByUuidForBusiness($uuid, $business->id);
        $this->spaBranchRepository->delete($uuid);
        return true;
    }

    public function restoreSpaBranch(string $uuid)
    {
        $model = $this->spaBranchRepository->restore($uuid);
        return new SpaBranchResource($model);
    }

    /**
     * Submits (or resubmits) the map pin for admin review. Only allowed from
     * Unregistered (first submission) or Rejected (owner fixed the pin and
     * is trying again) — a branch that's already Pending/Verified/Suspended
     * can't be resubmitted out from under the admin's review.
     */
    public function submitRegistration(User $user, string $uuid, array $payload, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $branch = $this->spaBranchRepository->findByUuidForBusiness($uuid, $business->id);

        if (! in_array($branch->verification_status, ['Unregistered', 'Rejected'], true)) {
            return response()->json([
                'message' => 'This branch already has a registration request in review or approved.',
            ], 422);
        }

        $oldValues = $branch->only(['latitude', 'longitude', 'verification_status']);

        // Only the coordinates are saved — address/city/province/postal_code
        // stay exactly as the owner typed them when creating the branch.
        // Review compares that typed address against the map itself (the
        // pin at these coordinates), not against a second machine-generated
        // address string.
        $branch = $this->spaBranchRepository->update($uuid, [
            'latitude' => $payload['latitude'],
            'longitude' => $payload['longitude'],
            'verification_status' => 'Pending',
            'rejection_reason' => null,
            'verified_by' => null,
            'verified_at' => null,
        ]);

        $this->auditLogRepository->record(
            $user->id,
            'spa_branches',
            $branch->id,
            'Update',
            $oldValues,
            $branch->only(['latitude', 'longitude', 'verification_status']),
            $request
        );

        $this->notificationService->branchRegistrationSubmitted($branch, $this->adminUsersRepository->allAdministrators());

        return new SpaBranchResource($branch);
    }
}
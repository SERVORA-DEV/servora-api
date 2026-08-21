<?php

namespace App\Service\Business;

use App\Models\User;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Repository\System\AdminUsersRepository;
use App\Repository\AuditLogRepository;
use App\Http\Resources\SpaBranchResource;
use App\Service\NotificationService;
use App\Services\DocumentUploadService;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class SpaBranchService
{
    private SpaBranchRepository $spaBranchRepository;
    private SpaBusinessRepository $spaBusinessRepository;
    private AdminUsersRepository $adminUsersRepository;
    private AuditLogRepository $auditLogRepository;
    private NotificationService $notificationService;
    private ImageUploadService $imageUploadService;
    private DocumentUploadService $documentUploadService;

    public function __construct(
        SpaBranchRepository $spaBranchRepository,
        SpaBusinessRepository $spaBusinessRepository,
        AdminUsersRepository $adminUsersRepository,
        AuditLogRepository $auditLogRepository,
        NotificationService $notificationService,
        ImageUploadService $imageUploadService,
        DocumentUploadService $documentUploadService
    ) {
        $this->spaBranchRepository = $spaBranchRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->adminUsersRepository = $adminUsersRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->notificationService = $notificationService;
        $this->imageUploadService = $imageUploadService;
        $this->documentUploadService = $documentUploadService;
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
     * payload. Location and the permit are separate wizard steps (see
     * saveLocation/savePermit below) — this only handles the Info step
     * (name/contact/photo).
     */
    public function createSpaBranch(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        if (isset($payload['cover_photo']) && $payload['cover_photo'] instanceof UploadedFile) {
            $payload['cover_photo'] = $this->imageUploadService->store(
                $payload['cover_photo'],
                "branches/{$business->uuid}"
            );
        } else {
            unset($payload['cover_photo']);
        }

        $payload['spa_business_id'] = $business->id;

        // A newly created branch isn't registered yet — the owner still has
        // to complete Location + Permit and submit() (see below) before it
        // can go to Pending.
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

        $branch = $this->spaBranchRepository->findByUuidForBusiness($uuid, $business->id);

        if (isset($payload['cover_photo']) && $payload['cover_photo'] instanceof UploadedFile) {
            $payload['cover_photo'] = $this->imageUploadService->replace(
                $branch->cover_photo,
                $payload['cover_photo'],
                "branches/{$business->uuid}"
            );
        } else {
            unset($payload['cover_photo']);
        }

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
     * Location step of the branch wizard — saves the confirmed map pin plus
     * whatever address components the frontend resolved for it. Never
     * changes verification_status on its own (unlike the old
     * submitRegistration, which flipped straight to Pending off the pin
     * alone) — only submit() below does that, once Permit is complete too.
     * Same Unregistered/Rejected guard as every other draft-mutating step.
     */
    public function saveLocation(User $user, string $uuid, array $payload, ?Request $request = null)
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

        $oldValues = $branch->only(['latitude', 'longitude', 'formatted_address']);

        $branch = $this->spaBranchRepository->update($uuid, [
            'latitude' => $payload['latitude'],
            'longitude' => $payload['longitude'],
            'formatted_address' => $payload['formatted_address'],
            'address' => $payload['address'] ?? $branch->address,
            'city' => $payload['city'] ?? $branch->city,
            'province' => $payload['province'] ?? $branch->province,
            'postal_code' => $payload['postal_code'] ?? $branch->postal_code,
        ]);

        $this->auditLogRepository->record(
            $user->id,
            'spa_branches',
            $branch->id,
            'Update',
            $oldValues,
            $branch->only(['latitude', 'longitude', 'formatted_address']),
            $request
        );

        return new SpaBranchResource($branch);
    }

    /**
     * Permit step of the branch wizard — the branch's own Business/Mayor's
     * Permit, kept separate from the business-level DTI/SEC document (that
     * already verified the business itself; this proves the specific
     * location). Uses DocumentUploadService (private/authenticated), same
     * as the business registration document. Same Unregistered/Rejected
     * guard as saveLocation.
     */
    public function savePermit(User $user, string $uuid, array $payload, UploadedFile $permitFile, ?Request $request = null)
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

        $oldValues = $branch->only(['permit_number', 'permit_confirmed']);

        $path = $this->documentUploadService->replace(
            $branch->permit_document_path,
            $permitFile,
            "verification/branch/{$branch->uuid}/permit"
        );

        $branch = $this->spaBranchRepository->update($uuid, [
            'permit_document_path' => $path,
            'permit_number' => $payload['permit_number'],
            'permit_business_name' => $payload['permit_business_name'],
            'permit_branch_location' => $payload['permit_branch_location'],
            'permit_issue_date' => $payload['permit_issue_date'],
            'permit_expiration_date' => $payload['permit_expiration_date'],
            'permit_confirmed' => true,
        ]);

        $this->auditLogRepository->record(
            $user->id,
            'spa_branches',
            $branch->id,
            'Upload',
            $oldValues,
            $branch->only(['permit_number', 'permit_confirmed']),
            $request
        );

        return new SpaBranchResource($branch);
    }

    /**
     * The combined "Review -> Submit for Verification" action. Only allowed
     * from Unregistered (first submission) or Rejected (owner fixed the
     * flagged step and is trying again) — a branch that's already
     * Pending/Verified/Suspended can't be resubmitted out from under the
     * admin's review. Requires Info + Location + Permit to all be complete,
     * mirroring OwnerVerificationService::submit()'s completeness checks.
     */
    public function submit(User $user, string $uuid, ?Request $request = null)
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

        if (! $branch->branch_name) {
            return response()->json([
                'message' => 'Please complete the branch information before submitting.',
            ], 422);
        }

        if ($branch->latitude === null || $branch->longitude === null) {
            return response()->json([
                'message' => 'Please select this branch\'s location on the map before submitting.',
            ], 422);
        }

        if (! $branch->permit_document_path || ! $branch->permit_confirmed) {
            return response()->json([
                'message' => 'Please upload this branch\'s business permit before submitting.',
            ], 422);
        }

        $oldValues = $branch->only(['verification_status']);

        $branch = $this->spaBranchRepository->update($uuid, [
            'verification_status' => 'Pending',
            'rejection_reason' => null,
            'verified_by' => null,
            'verified_at' => null,
        ]);

        $this->auditLogRepository->record(
            $user->id,
            'spa_branches',
            $branch->id,
            'Submit',
            $oldValues,
            $branch->only(['verification_status']),
            $request
        );

        $this->notificationService->branchRegistrationSubmitted($branch, $this->adminUsersRepository->allAdministrators());

        return new SpaBranchResource($branch);
    }
}
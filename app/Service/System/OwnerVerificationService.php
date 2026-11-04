<?php

namespace App\Service\System;

use App\Http\Resources\OwnerVerificationResource;
use App\Models\OwnerIdentityVerification;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\SpaBusinessRepository;
use App\Repository\System\OwnerVerificationRepository;
use App\Service\NotificationService;
use App\Support\OwnerVerificationStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OwnerVerificationService
{
    public function __construct(
        private OwnerVerificationRepository $ownerVerificationRepository,
        private SpaBusinessRepository $spaBusinessRepository,
        private AuditLogRepository $auditLogRepository,
        private NotificationService $notificationService,
        private OwnerVerificationChecklistService $checklists,
    ) {}

    public function listPending(int $perPage = 15)
    {
        $collection = $this->ownerVerificationRepository->paginatePending($perPage);

        return OwnerVerificationResource::collection($collection->through(
            fn (SpaBusiness $business) => (object) [
                'business' => $business,
                'identity' => $business->owner?->ownerIdentityVerification ?? new OwnerIdentityVerification(['status' => 'Unregistered']),
                'owner' => $business->owner,
            ]
        ));
    }

    public function getReview(string $uuid)
    {
        $business = $this->ownerVerificationRepository->findByUuid($uuid);
        $identity = $business->owner?->ownerIdentityVerification ?? new OwnerIdentityVerification(['status' => 'Unregistered']);

        return (new OwnerVerificationResource((object) [
            'business' => $business,
            'identity' => $identity,
            'owner' => $business->owner,
        ]))->additional(['review_checklists' => $this->checklists->forBusiness($business)]);
    }

    // Each final decision locks the application, checks the persisted review,
    // and writes its status and audit entry in one transaction.
    public function approveIdentity(User $admin, string $uuid, ?Request $request = null)
    {
        return DB::transaction(fn () => $this->approveIdentityLocked($admin, $uuid, $request), 3);
    }

    public function rejectIdentity(User $admin, string $uuid, string $reason, ?string $field, ?Request $request = null, bool $resubmission = false)
    {
        return DB::transaction(fn () => $this->rejectIdentityLocked($admin, $uuid, $reason, $field, $request, $resubmission), 3);
    }

    public function approveBusiness(User $admin, string $uuid, ?Request $request = null)
    {
        return DB::transaction(fn () => $this->approveBusinessLocked($admin, $uuid, $request), 3);
    }

    public function rejectBusiness(User $admin, string $uuid, string $reason, ?Request $request = null, bool $resubmission = false)
    {
        return DB::transaction(fn () => $this->rejectBusinessLocked($admin, $uuid, $reason, $request, $resubmission), 3);
    }

    // Identity and business verification are decided independently (spec
    // §12) — an admin can approve one while the other stays Pending or gets
    // rejected. Guarded so only a currently-Pending part can be decided,
    // mirroring BranchRegistrationService::approve/reject.
    private function approveIdentityLocked(User $admin, string $uuid, ?Request $request = null)
    {
        $business = $this->ownerVerificationRepository->findForReviewUpdate($uuid);
        $identity = $business->owner?->ownerIdentityVerification;

        if (! $identity || $identity->status !== 'Pending') {
            return response()->json(['message' => 'Only a pending identity verification can be approved.'], 422);
        }

        $review = $this->checklists->authorizeDecision($business, 'identity', true, $request);

        $oldValues = $identity->only(['status', 'verified_by', 'verified_at', 'rejection_reason']);

        $identity = $this->ownerVerificationRepository->updateIdentity($identity, [
            'status' => 'Verified',
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => null,
            'rejected_field' => null,
        ]);

        $reviewValues = $this->checklists->recordDecision($review, $admin, 'approved');

        $this->auditLogRepository->record(
            $admin->id,
            'owner_identity_verifications',
            $identity->id,
            'Approve',
            $oldValues,
            array_merge($identity->only(['status', 'verified_by', 'verified_at', 'rejection_reason']), $reviewValues),
            $request
        );

        DB::afterCommit(function () use ($business, $identity) {
            try {
                if ($business->owner) {
                    $this->notificationService->ownerIdentityApproved($business->owner);
                    $this->notifyIfFullyApproved($business, $identity);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $this->getReview($uuid);
    }

    private function rejectIdentityLocked(User $admin, string $uuid, string $reason, ?string $field, ?Request $request = null, bool $resubmission = false)
    {
        $business = $this->ownerVerificationRepository->findForReviewUpdate($uuid);
        $identity = $business->owner?->ownerIdentityVerification;

        if (! $identity || $identity->status !== 'Pending') {
            return response()->json(['message' => 'Only a pending identity verification can be rejected.'], 422);
        }

        $review = $this->checklists->authorizeDecision($business, 'identity', false, $request);

        $oldValues = $identity->only(['status', 'verified_by', 'verified_at', 'rejection_reason']);

        $identity = $this->ownerVerificationRepository->updateIdentity($identity, [
            'status' => 'Rejected',
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
            'rejected_field' => $field ?? 'both',
        ]);

        $reviewValues = $this->checklists->recordDecision($review, $admin, $resubmission ? 'resubmission_requested' : 'rejected');

        $this->auditLogRepository->record(
            $admin->id,
            'owner_identity_verifications',
            $identity->id,
            'Reject',
            $oldValues,
            array_merge($identity->only(['status', 'verified_by', 'verified_at', 'rejection_reason']), $reviewValues),
            $request
        );

        DB::afterCommit(function () use ($business, $identity, $reason) {
            try {
                if ($business->owner) {
                    $this->notificationService->ownerIdentityRejected($business->owner, $reason);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $this->getReview($uuid);
    }

    private function approveBusinessLocked(User $admin, string $uuid, ?Request $request = null)
    {
        $business = $this->ownerVerificationRepository->findForReviewUpdate($uuid);

        if ($business->verification_status !== 'Pending') {
            return response()->json(['message' => 'Only a pending business verification can be approved.'], 422);
        }

        $review = $this->checklists->authorizeDecision($business, 'business', true, $request);

        $oldValues = $business->only(['verification_status', 'verified_by', 'verified_at', 'rejection_reason']);

        $business = $this->spaBusinessRepository->update($business, [
            'verification_status' => 'Verified',
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => null,
        ]);

        $reviewValues = $this->checklists->recordDecision($review, $admin, 'approved');

        $this->auditLogRepository->record(
            $admin->id,
            'spa_businesses',
            $business->id,
            'Approve',
            $oldValues,
            array_merge($business->only(['verification_status', 'verified_by', 'verified_at', 'rejection_reason']), $reviewValues),
            $request
        );

        DB::afterCommit(function () use ($business) {
            try {
                if ($business->owner) {
                    $this->notificationService->ownerBusinessApproved($business, $business->owner);

                    $identity = $business->owner->ownerIdentityVerification;
                    if ($identity) {
                        $this->notifyIfFullyApproved($business, $identity);
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $this->getReview($uuid);
    }

    private function rejectBusinessLocked(User $admin, string $uuid, string $reason, ?Request $request = null, bool $resubmission = false)
    {
        $business = $this->ownerVerificationRepository->findForReviewUpdate($uuid);

        if ($business->verification_status !== 'Pending') {
            return response()->json(['message' => 'Only a pending business verification can be rejected.'], 422);
        }

        $review = $this->checklists->authorizeDecision($business, 'business', false, $request);

        $oldValues = $business->only(['verification_status', 'verified_by', 'verified_at', 'rejection_reason']);

        $business = $this->spaBusinessRepository->update($business, [
            'verification_status' => 'Rejected',
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $reviewValues = $this->checklists->recordDecision($review, $admin, $resubmission ? 'resubmission_requested' : 'rejected');

        $this->auditLogRepository->record(
            $admin->id,
            'spa_businesses',
            $business->id,
            'Reject',
            $oldValues,
            array_merge($business->only(['verification_status', 'verified_by', 'verified_at', 'rejection_reason']), $reviewValues),
            $request
        );

        DB::afterCommit(function () use ($business, $reason) {
            try {
                if ($business->owner) {
                    $this->notificationService->ownerBusinessRejected($business, $business->owner, $reason);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $this->getReview($uuid);
    }

    private function notifyIfFullyApproved(SpaBusiness $business, OwnerIdentityVerification $identity): void
    {
        $overall = OwnerVerificationStatus::compute($identity->status, $business->verification_status);

        if ($overall === 'VERIFIED' && $business->owner) {
            $this->notificationService->ownerVerificationFullyApproved($business->owner);
        }
    }
}

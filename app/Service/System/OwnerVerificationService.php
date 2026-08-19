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

class OwnerVerificationService
{
    public function __construct(
        private OwnerVerificationRepository $ownerVerificationRepository,
        private SpaBusinessRepository $spaBusinessRepository,
        private AuditLogRepository $auditLogRepository,
        private NotificationService $notificationService,
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

        return new OwnerVerificationResource((object) [
            'business' => $business,
            'identity' => $identity,
            'owner' => $business->owner,
        ]);
    }

    // Identity and business verification are decided independently (spec
    // §12) — an admin can approve one while the other stays Pending or gets
    // rejected. Guarded so only a currently-Pending part can be decided,
    // mirroring BranchRegistrationService::approve/reject.
    public function approveIdentity(User $admin, string $uuid, ?Request $request = null)
    {
        $business = $this->ownerVerificationRepository->findByUuid($uuid);
        $identity = $business->owner?->ownerIdentityVerification;

        if (! $identity || $identity->status !== 'Pending') {
            return response()->json(['message' => 'Only a pending identity verification can be approved.'], 422);
        }

        $oldValues = $identity->only(['status', 'verified_by', 'verified_at', 'rejection_reason']);

        $identity = $this->ownerVerificationRepository->updateIdentity($identity, [
            'status' => 'Verified',
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => null,
            'rejected_field' => null,
        ]);

        $this->auditLogRepository->record(
            $admin->id,
            'owner_identity_verifications',
            $identity->id,
            'Approve',
            $oldValues,
            $identity->only(['status', 'verified_by', 'verified_at', 'rejection_reason']),
            $request
        );

        if ($business->owner) {
            $this->notificationService->ownerIdentityApproved($business->owner);
            $this->notifyIfFullyApproved($business, $identity);
        }

        return $this->getReview($uuid);
    }

    public function rejectIdentity(User $admin, string $uuid, string $reason, ?string $field, ?Request $request = null)
    {
        $business = $this->ownerVerificationRepository->findByUuid($uuid);
        $identity = $business->owner?->ownerIdentityVerification;

        if (! $identity || $identity->status !== 'Pending') {
            return response()->json(['message' => 'Only a pending identity verification can be rejected.'], 422);
        }

        $oldValues = $identity->only(['status', 'verified_by', 'verified_at', 'rejection_reason']);

        $identity = $this->ownerVerificationRepository->updateIdentity($identity, [
            'status' => 'Rejected',
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
            'rejected_field' => $field ?? 'both',
        ]);

        $this->auditLogRepository->record(
            $admin->id,
            'owner_identity_verifications',
            $identity->id,
            'Reject',
            $oldValues,
            $identity->only(['status', 'verified_by', 'verified_at', 'rejection_reason']),
            $request
        );

        if ($business->owner) {
            $this->notificationService->ownerIdentityRejected($business->owner, $reason);
        }

        return $this->getReview($uuid);
    }

    public function approveBusiness(User $admin, string $uuid, ?Request $request = null)
    {
        $business = $this->ownerVerificationRepository->findByUuid($uuid);

        if ($business->verification_status !== 'Pending') {
            return response()->json(['message' => 'Only a pending business verification can be approved.'], 422);
        }

        $oldValues = $business->only(['verification_status', 'verified_by', 'verified_at', 'rejection_reason']);

        $business = $this->spaBusinessRepository->update($business, [
            'verification_status' => 'Verified',
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => null,
        ]);

        $this->auditLogRepository->record(
            $admin->id,
            'spa_businesses',
            $business->id,
            'Approve',
            $oldValues,
            $business->only(['verification_status', 'verified_by', 'verified_at', 'rejection_reason']),
            $request
        );

        if ($business->owner) {
            $this->notificationService->ownerBusinessApproved($business, $business->owner);

            $identity = $business->owner->ownerIdentityVerification;
            if ($identity) {
                $this->notifyIfFullyApproved($business, $identity);
            }
        }

        return $this->getReview($uuid);
    }

    public function rejectBusiness(User $admin, string $uuid, string $reason, ?Request $request = null)
    {
        $business = $this->ownerVerificationRepository->findByUuid($uuid);

        if ($business->verification_status !== 'Pending') {
            return response()->json(['message' => 'Only a pending business verification can be rejected.'], 422);
        }

        $oldValues = $business->only(['verification_status', 'verified_by', 'verified_at', 'rejection_reason']);

        $business = $this->spaBusinessRepository->update($business, [
            'verification_status' => 'Rejected',
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $this->auditLogRepository->record(
            $admin->id,
            'spa_businesses',
            $business->id,
            'Reject',
            $oldValues,
            $business->only(['verification_status', 'verified_by', 'verified_at', 'rejection_reason']),
            $request
        );

        if ($business->owner) {
            $this->notificationService->ownerBusinessRejected($business, $business->owner, $reason);
        }

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

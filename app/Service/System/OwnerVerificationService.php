<?php

namespace App\Service\System;

use App\Enums\VerificationStatus;
use App\Http\Resources\OwnerVerificationResource;
use App\Models\OwnerIdentityVerification;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\SpaBusinessRepository;
use App\Repository\System\OwnerVerificationRepository;
use App\Service\Notifications\NotificationService;
use App\Service\Notifications\RegistrationDecisionEmail;
use App\Support\Platform\OwnerVerificationStatus;
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
        private RegistrationDecisionEmail $decisionEmail,
    ) {}

    public function listPending(int $perPage = 15)
    {
        $collection = $this->ownerVerificationRepository->paginatePending($perPage);

        return OwnerVerificationResource::collection($collection->through(
            fn (SpaBusiness $business) => (object) [
                'business' => $business,
                'identity' => $business->owner?->ownerIdentityVerification ?? new OwnerIdentityVerification(['status' => VerificationStatus::Unregistered->value]),
                'owner' => $business->owner,
            ]
        ));
    }

    public function getReview(string $uuid)
    {
        $business = $this->ownerVerificationRepository->findByUuid($uuid);
        $identity = $business->owner?->ownerIdentityVerification ?? new OwnerIdentityVerification(['status' => VerificationStatus::Unregistered->value]);

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

        if (! $identity || $identity->status !== VerificationStatus::Pending->value) {
            return response()->json(['message' => 'Only a pending identity verification can be approved.'], 422);
        }

        $review = $this->checklists->authorizeDecision($business, 'identity', true, $request);

        $oldValues = $identity->only(['status', 'verified_by', 'verified_at', 'rejection_reason']);

        $identity = $this->ownerVerificationRepository->updateIdentity($identity, [
            'status' => VerificationStatus::Verified->value,
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

        $this->decisionEmail->afterCommit($business->owner, 'Owner Identity Verification', '/business/verification', approved: true);

        return $this->getReview($uuid);
    }

    private function rejectIdentityLocked(User $admin, string $uuid, string $reason, ?string $field, ?Request $request = null, bool $resubmission = false)
    {
        $business = $this->ownerVerificationRepository->findForReviewUpdate($uuid);
        $identity = $business->owner?->ownerIdentityVerification;

        if (! $identity || $identity->status !== VerificationStatus::Pending->value) {
            return response()->json(['message' => 'Only a pending identity verification can be rejected.'], 422);
        }

        $review = $this->checklists->authorizeDecision($business, 'identity', false, $request);
        $field ??= 'both';
        $nextStep = match ($field) {
            'face_scan' => 'Retake your live face scan.',
            'id_document' => 'Upload your government ID again.',
            default => 'Upload your government ID again and retake your live face scan.',
        };

        $oldValues = $identity->only(['status', 'verified_by', 'verified_at', 'rejection_reason']);

        $identity = $this->ownerVerificationRepository->updateIdentity($identity, [
            'status' => VerificationStatus::Rejected->value,
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
            'rejected_field' => $field,
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

        DB::afterCommit(function () use ($business, $reason, $nextStep) {
            try {
                if ($business->owner) {
                    $this->notificationService->ownerIdentityRejected($business->owner, $reason, $nextStep);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        $this->decisionEmail->afterCommit($business->owner, 'Owner Identity Verification', '/business/verification', approved: false, nextStep: $nextStep);

        return $this->getReview($uuid);
    }

    private function approveBusinessLocked(User $admin, string $uuid, ?Request $request = null)
    {
        $business = $this->ownerVerificationRepository->findForReviewUpdate($uuid);

        if ($business->verification_status !== VerificationStatus::Pending->value) {
            return response()->json(['message' => 'Only a pending business verification can be approved.'], 422);
        }

        $review = $this->checklists->authorizeDecision($business, 'business', true, $request);

        $oldValues = $business->only(['verification_status', 'verified_by', 'verified_at', 'rejection_reason']);

        $business = $this->spaBusinessRepository->update($business, [
            'verification_status' => VerificationStatus::Verified->value,
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

        $this->decisionEmail->afterCommit($business->owner, 'Business Registration', '/business/verification', approved: true, recordName: $business->business_name);

        return $this->getReview($uuid);
    }

    private function rejectBusinessLocked(User $admin, string $uuid, string $reason, ?Request $request = null, bool $resubmission = false)
    {
        $business = $this->ownerVerificationRepository->findForReviewUpdate($uuid);

        if ($business->verification_status !== VerificationStatus::Pending->value) {
            return response()->json(['message' => 'Only a pending business verification can be rejected.'], 422);
        }

        $review = $this->checklists->authorizeDecision($business, 'business', false, $request);

        $oldValues = $business->only(['verification_status', 'verified_by', 'verified_at', 'rejection_reason']);

        $business = $this->spaBusinessRepository->update($business, [
            'verification_status' => VerificationStatus::Rejected->value,
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

        $this->decisionEmail->afterCommit($business->owner, 'Business Registration', '/business/verification', approved: false,
            recordName: $business->business_name, nextStep: 'Update your business registration and send it again.');

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

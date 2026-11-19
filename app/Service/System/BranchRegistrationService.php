<?php

namespace App\Service\System;

use App\Enums\VerificationStatus;
use App\Models\User;
use App\Repository\System\BranchRegistrationRepository;
use App\Repository\AuditLogRepository;
use App\Http\Resources\SpaBranchResource;
use App\Service\Notifications\NotificationService;
use Illuminate\Http\Request;

class BranchRegistrationService
{
    public function __construct(
        private BranchRegistrationRepository $branchRegistrationRepository,
        private AuditLogRepository $auditLogRepository,
        private NotificationService $notificationService,
    ) {}

    public function listPending(int $perPage = 15)
    {
        $collection = $this->branchRegistrationRepository->paginatePending($perPage);
        return SpaBranchResource::collection($collection);
    }

    public function getRegistration(string $uuid)
    {
        $model = $this->branchRegistrationRepository->findByUuid($uuid);
        return new SpaBranchResource($model);
    }

    // Guards against approving/rejecting a branch twice (or one that was
    // never submitted) — only a branch currently awaiting review can be
    // decided on.
    public function approve(User $admin, string $uuid, ?Request $request = null)
    {
        $branch = $this->branchRegistrationRepository->findByUuid($uuid);

        if ($branch->verification_status !== VerificationStatus::Pending->value) {
            return response()->json([
                'message' => 'Only a pending registration can be approved.',
            ], 422);
        }

        $oldValues = $branch->only(['verification_status', 'verified_by', 'verified_at', 'rejection_reason']);

        $branch = $this->branchRegistrationRepository->update($uuid, [
            'verification_status' => VerificationStatus::Verified->value,
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => null,
        ]);

        $this->auditLogRepository->record(
            $admin->id,
            'spa_branches',
            $branch->id,
            'Approve',
            $oldValues,
            $branch->only(['verification_status', 'verified_by', 'verified_at', 'rejection_reason']),
            $request
        );

        if ($branch->business?->owner) {
            $this->notificationService->branchRegistrationApproved($branch, $branch->business->owner);
        }

        return new SpaBranchResource($branch);
    }

    public function reject(User $admin, string $uuid, string $reason, ?Request $request = null)
    {
        $branch = $this->branchRegistrationRepository->findByUuid($uuid);

        if ($branch->verification_status !== VerificationStatus::Pending->value) {
            return response()->json([
                'message' => 'Only a pending registration can be rejected.',
            ], 422);
        }

        $oldValues = $branch->only(['verification_status', 'verified_by', 'verified_at', 'rejection_reason']);

        $branch = $this->branchRegistrationRepository->update($uuid, [
            'verification_status' => VerificationStatus::Rejected->value,
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $this->auditLogRepository->record(
            $admin->id,
            'spa_branches',
            $branch->id,
            'Reject',
            $oldValues,
            $branch->only(['verification_status', 'verified_by', 'verified_at', 'rejection_reason']),
            $request
        );

        if ($branch->business?->owner) {
            $this->notificationService->branchRegistrationRejected($branch, $branch->business->owner, $reason);
        }

        return new SpaBranchResource($branch);
    }
}

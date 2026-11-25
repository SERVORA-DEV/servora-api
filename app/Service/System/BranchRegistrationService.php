<?php

namespace App\Service\System;

use App\Enums\VerificationStatus;
use App\Models\User;
use App\Repository\System\BranchRegistrationRepository;
use App\Repository\AuditLogRepository;
use App\Http\Resources\SpaBranchResource;
use App\Service\Notifications\NotificationService;
use App\Support\Platform\Suspension;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function approve(User $admin, string $uuid, ?Request $request = null)
    {
        return DB::transaction(function () use ($admin, $uuid, $request) {
            $branch = $this->branchRegistrationRepository->findByUuid($uuid, lock: true);
            if ($branch->verification_status !== VerificationStatus::Pending->value) {
                return response()->json(['message' => 'Only a pending registration can be approved.'], 422);
            }

            $fields = ['verification_status', 'verified_by', 'verified_at', 'rejection_reason', 'suspension_reason'];
            $oldValues = $branch->only($fields);
            $wasSuspended = $branch->suspension_reason !== null;
            $branch->update([
                'verification_status' => VerificationStatus::Verified->value,
                'verified_by' => $admin->id,
                'verified_at' => now(),
                'rejection_reason' => null,
                'suspension_reason' => null,
            ]);

            $this->auditLogRepository->record($admin->id, 'spa_branches', $branch->id, 'Approve', $oldValues, $branch->only($fields), $request);

            if ($wasSuspended) {
                DB::afterCommit(function () use ($branch) {
                    try {
                        if ($owner = $branch->business?->owner) {
                            $this->notificationService->branchReactivated($branch, $owner);
                        }
                        Suspension::tellClientsBack([$branch->id]);
                    } catch (\Throwable $error) {
                        report($error);
                    }
                });
            } elseif ($owner = $branch->business?->owner) {
                $this->notificationService->branchRegistrationApproved($branch, $owner);
            }

            return new SpaBranchResource($branch->load('verifier'));
        });
    }

    public function reject(User $admin, string $uuid, string $reason, ?Request $request = null)
    {
        return DB::transaction(function () use ($admin, $uuid, $reason, $request) {
            $branch = $this->branchRegistrationRepository->findByUuid($uuid, lock: true);
            if ($branch->verification_status !== VerificationStatus::Pending->value) {
                return response()->json(['message' => 'Only a pending registration can be rejected.'], 422);
            }

            $fields = ['verification_status', 'verified_by', 'verified_at', 'rejection_reason'];
            $oldValues = $branch->only($fields);
            $branch->update([
                'verification_status' => VerificationStatus::Rejected->value,
                'verified_by' => $admin->id,
                'verified_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->auditLogRepository->record($admin->id, 'spa_branches', $branch->id, 'Reject', $oldValues, $branch->only($fields), $request);

            if ($owner = $branch->business?->owner) {
                $this->notificationService->branchRegistrationRejected($branch, $owner, $reason);
            }

            return new SpaBranchResource($branch->load('verifier'));
        });
    }
}

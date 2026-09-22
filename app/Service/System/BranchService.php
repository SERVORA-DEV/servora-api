<?php

namespace App\Service\System;

use App\Http\Resources\SpaBranchResource;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\System\BranchRepository;
use App\Service\NotificationService;
use Illuminate\Http\Request;

class BranchService
{
    public function __construct(
        private BranchRepository $branchRepository,
        private AuditLogRepository $auditLogRepository,
        private NotificationService $notificationService,
    ) {}

    // 'total' only counts Verified + Suspended (the branches this list
    // actually shows) — 'pending' is informational, surfaced here so the
    // page's stat row can link out to /system/pending without a second
    // request, not because pending branches are part of this list.
    public function listBranches(int $perPage = 100)
    {
        $paginator = $this->branchRepository->paginateAll($perPage);

        $active = $this->branchRepository->countByVerificationStatus('Verified');
        $disabled = $this->branchRepository->countByVerificationStatus('Suspended');
        $pending = $this->branchRepository->countByVerificationStatus('Pending');

        return SpaBranchResource::collection($paginator)->additional([
            'stats' => [
                'total' => $active + $disabled,
                'active' => $active,
                'disabled' => $disabled,
                'pending' => $pending,
            ],
        ]);
    }

    public function getBranch(string $uuid): SpaBranchResource
    {
        return new SpaBranchResource($this->branchRepository->findByUuid($uuid));
    }

    // Only a currently-Verified branch can be suspended — same guard shape
    // as BusinessService::suspend.
    public function suspend(User $admin, string $uuid, string $reason, ?Request $request = null)
    {
        $branch = $this->branchRepository->findForList($uuid);

        if ($branch->verification_status !== 'Verified') {
            return response()->json(['message' => 'Only a verified branch can be suspended.'], 422);
        }

        $oldValues = $branch->only(['verification_status', 'verified_by', 'verified_at', 'suspension_reason']);

        $branch = $this->branchRepository->update($branch, [
            'verification_status' => 'Suspended',
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'suspension_reason' => $reason,
        ]);

        $this->auditLogRepository->record(
            $admin->id,
            'spa_branches',
            $branch->id,
            'Suspend',
            $oldValues,
            $branch->only(['verification_status', 'verified_by', 'verified_at', 'suspension_reason']),
            $request
        );

        $owner = $branch->business?->owner;
        if ($owner) {
            $this->notificationService->branchSuspended($branch, $owner, $reason);
        }

        return new SpaBranchResource($this->branchRepository->findForList($uuid));
    }

    public function reactivate(User $admin, string $uuid, ?Request $request = null)
    {
        $branch = $this->branchRepository->findForList($uuid);

        if ($branch->verification_status !== 'Suspended') {
            return response()->json(['message' => 'Only a suspended branch can be reactivated.'], 422);
        }

        $oldValues = $branch->only(['verification_status', 'verified_by', 'verified_at', 'suspension_reason']);

        $branch = $this->branchRepository->update($branch, [
            'verification_status' => 'Verified',
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'suspension_reason' => null,
        ]);

        $this->auditLogRepository->record(
            $admin->id,
            'spa_branches',
            $branch->id,
            'Restore',
            $oldValues,
            $branch->only(['verification_status', 'verified_by', 'verified_at', 'suspension_reason']),
            $request
        );

        $owner = $branch->business?->owner;
        if ($owner) {
            $this->notificationService->branchReactivated($branch, $owner);
        }

        return new SpaBranchResource($this->branchRepository->findForList($uuid));
    }
}

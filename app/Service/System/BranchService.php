<?php

namespace App\Service\System;

use App\Enums\VerificationStatus;
use App\Http\Resources\SpaBranchResource;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\System\BranchRepository;
use App\Service\Notifications\NotificationService;
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
    /** One page of branches, filtered by the API, with the stat cards and the filters' options. */
    public function listBranches(int $perPage = 100, array $filters = [])
    {
        $paginator = $this->branchRepository->paginateAll($perPage, $filters);

        $active = $this->branchRepository->countByVerificationStatus(VerificationStatus::Verified->value);
        $disabled = $this->branchRepository->countByVerificationStatus(VerificationStatus::Suspended->value);
        $pending = $this->branchRepository->countByVerificationStatus(VerificationStatus::Pending->value);

        // Slim rows: what the table and the map show (the detail reads the rest).
        return \App\Http\Resources\System\BranchListResource::collection($paginator)->additional([
            'stats' => [
                'total' => $active + $disabled,
                'active' => $active,
                'disabled' => $disabled,
                'pending' => $pending,
            ],
            'operating_counts' => $this->branchRepository->operatingCounts(),
            'business_options' => $this->branchRepository->businessOptions(),
        ]);
    }

    public function getBranch(string $uuid): SpaBranchResource
    {
        return new SpaBranchResource($this->branchRepository->findByUuid($uuid));
    }

    // Only a currently-Verified branch can be suspended — same guard shape
    // as BusinessService::suspend.
    // What suspending it would reach, shown before the administrator confirms.
    public function suspensionImpact(string $uuid): array
    {
        $branch = $this->branchRepository->findForList($uuid);

        return ['data' => \App\Support\Platform\Suspension::impact([$branch->id])];
    }

    public function suspend(User $admin, string $uuid, string $reason, ?Request $request = null)
    {
        $branch = $this->branchRepository->findForList($uuid);

        if ($branch->verification_status !== VerificationStatus::Verified->value) {
            return response()->json(['message' => 'Only a verified branch can be suspended.'], 422);
        }

        $oldValues = $branch->only(['verification_status', 'verified_by', 'verified_at', 'suspension_reason']);

        $branch = $this->branchRepository->update($branch, [
            'verification_status' => VerificationStatus::Suspended->value,
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
        // The clients booked for later are told it is on hold.
        \App\Support\Platform\Suspension::tellClients([$branch->id]);

        return new SpaBranchResource($this->branchRepository->findForList($uuid));
    }

    public function reactivate(User $admin, string $uuid, ?Request $request = null)
    {
        $branch = $this->branchRepository->findForList($uuid);

        if ($branch->verification_status !== VerificationStatus::Suspended->value) {
            return response()->json(['message' => 'Only a suspended branch can be reactivated.'], 422);
        }

        $oldValues = $branch->only(['verification_status', 'verified_by', 'verified_at', 'suspension_reason']);

        $branch = $this->branchRepository->update($branch, [
            'verification_status' => VerificationStatus::Verified->value,
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
        // The clients who were told their booking was on hold are told it is on.
        \App\Support\Platform\Suspension::tellClientsBack([$branch->id]);

        return new SpaBranchResource($this->branchRepository->findForList($uuid));
    }
}

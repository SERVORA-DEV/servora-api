<?php

namespace App\Service\System;

use App\Enums\VerificationStatus;
use App\Http\Resources\SpaBranchResource;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\System\BranchRepository;
use App\Service\Notifications\NotificationService;
use App\Support\Platform\Suspension;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    // What suspending it would reach, shown before the administrator confirms.
    public function suspensionImpact(string $uuid): array
    {
        $branch = $this->branchRepository->findForList($uuid);

        return ['data' => Suspension::impact([$branch->id])];
    }

    public function suspend(User $admin, string $uuid, string $reason, ?Request $request = null)
    {
        return DB::transaction(function () use ($admin, $uuid, $reason, $request) {
            $branch = $this->branchRepository->findForList($uuid, lock: true);
            if ($branch->verification_status !== VerificationStatus::Verified->value) {
                return response()->json(['message' => 'Only a verified branch can be suspended.'], 422);
            }

            $fields = ['verification_status', 'verified_by', 'verified_at', 'suspension_reason'];
            $oldValues = $branch->only($fields);
            $branch->update([
                'verification_status' => VerificationStatus::Suspended->value,
                'verified_by' => $admin->id,
                'verified_at' => now(),
                'suspension_reason' => $reason,
            ]);

            $this->auditLogRepository->record($admin->id, 'spa_branches', $branch->id, 'Suspend', $oldValues, $branch->only($fields), $request);

            DB::afterCommit(function () use ($branch, $reason) {
                try {
                    if ($owner = $branch->business?->owner) {
                        $this->notificationService->branchSuspended($branch, $owner, $reason);
                    }
                    Suspension::tellClients([$branch->id]);
                } catch (\Throwable $error) {
                    report($error);
                }
            });

            return new SpaBranchResource($branch);
        });
    }
}

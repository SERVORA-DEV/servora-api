<?php

namespace App\Service\System;

use App\Http\Resources\System\BusinessDetailResource;
use App\Http\Resources\System\BusinessResource;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\System\BusinessRepository;
use App\Service\NotificationService;
use Illuminate\Http\Request;

class BusinessService
{
    public function __construct(
        private BusinessRepository $businessRepository,
        private AuditLogRepository $auditLogRepository,
        private NotificationService $notificationService,
    ) {}

    public function listBusinesses(int $perPage = 100)
    {
        $paginator = $this->businessRepository->paginateAll($perPage);

        return BusinessResource::collection($paginator)->additional([
            'stats' => [
                'total' => $paginator->total(),
                'active' => $this->businessRepository->countByVerificationStatus('Verified'),
                'pending' => $this->businessRepository->countByVerificationStatus('Pending'),
                'suspended' => $this->businessRepository->countByVerificationStatus('Suspended'),
            ],
        ]);
    }

    public function getBusiness(string $uuid): BusinessDetailResource
    {
        return new BusinessDetailResource($this->businessRepository->findByUuid($uuid));
    }

    // Only a currently-Verified business can be suspended — mirrors the
    // "only a pending X can be approved/rejected" guard used throughout
    // OwnerVerificationService/BranchRegistrationService.
    public function suspend(User $admin, string $uuid, string $reason, ?Request $request = null)
    {
        $business = $this->businessRepository->findForList($uuid);

        if ($business->verification_status !== 'Verified') {
            return response()->json(['message' => 'Only a verified business can be suspended.'], 422);
        }

        $oldValues = $business->only(['verification_status', 'verified_by', 'verified_at', 'suspension_reason']);

        $business = $this->businessRepository->update($business, [
            'verification_status' => 'Suspended',
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'suspension_reason' => $reason,
        ]);

        $this->auditLogRepository->record(
            $admin->id,
            'spa_businesses',
            $business->id,
            'Suspend',
            $oldValues,
            $business->only(['verification_status', 'verified_by', 'verified_at', 'suspension_reason']),
            $request
        );

        if ($business->owner) {
            $this->notificationService->businessSuspended($business, $business->owner, $reason);
        }

        return new BusinessResource($this->businessRepository->findForList($uuid));
    }

    public function reactivate(User $admin, string $uuid, ?Request $request = null)
    {
        $business = $this->businessRepository->findForList($uuid);

        if ($business->verification_status !== 'Suspended') {
            return response()->json(['message' => 'Only a suspended business can be reactivated.'], 422);
        }

        $oldValues = $business->only(['verification_status', 'verified_by', 'verified_at', 'suspension_reason']);

        $business = $this->businessRepository->update($business, [
            'verification_status' => 'Verified',
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'suspension_reason' => null,
        ]);

        $this->auditLogRepository->record(
            $admin->id,
            'spa_businesses',
            $business->id,
            'Restore',
            $oldValues,
            $business->only(['verification_status', 'verified_by', 'verified_at', 'suspension_reason']),
            $request
        );

        if ($business->owner) {
            $this->notificationService->businessReactivated($business, $business->owner);
        }

        return new BusinessResource($this->businessRepository->findForList($uuid));
    }
}

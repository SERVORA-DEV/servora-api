<?php

namespace App\Service\System;

use App\Enums\VerificationStatus;
use App\Http\Resources\System\BusinessDetailResource;
use App\Http\Resources\System\BusinessResource;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\System\BusinessRepository;
use App\Service\Business\CustomerProgramService;
use App\Service\Notifications\NotificationService;
use Illuminate\Http\Request;

class BusinessService
{
    public function __construct(
        private BusinessRepository $businessRepository,
        private AuditLogRepository $auditLogRepository,
        private NotificationService $notificationService,
        private CustomerProgramService $customerPrograms,
    ) {}

    // Read-only customer programs overview for the business's admin page.
    public function programs(string $uuid)
    {
        $business = \App\Models\SpaBusiness::where('uuid', $uuid)->firstOrFail();

        return response()->json(['data' => $this->customerPrograms->adminOverview($business)]);
    }

    /** One page of businesses, filtered by the API, with the stat cards and the plan options. */
    public function listBusinesses(int $perPage = 100, array $filters = [])
    {
        $paginator = $this->businessRepository->paginateAll($perPage, $filters);
        // Every business per status, whatever the filters (the stat cards and the overview chart).
        $by = \App\Models\SpaBusiness::groupBy('verification_status')->selectRaw('verification_status as s, count(*) as n')->pluck('n', 's');
        $n = fn (VerificationStatus $s) => (int) ($by[$s->value] ?? 0);
        $total = (int) $by->sum();
        $known = $n(VerificationStatus::Verified) + $n(VerificationStatus::Pending) + $n(VerificationStatus::Suspended) + $n(VerificationStatus::Rejected);

        return BusinessResource::collection($paginator)->additional([
            'plan_options' => $this->businessRepository->planOptions(),
            'stats' => [
                'total' => $total,
                'active' => $n(VerificationStatus::Verified),
                'pending' => $n(VerificationStatus::Pending),
                'suspended' => $n(VerificationStatus::Suspended),
                'rejected' => $n(VerificationStatus::Rejected),
                // Not sent in yet (anything else).
                'unverified' => $total - $known,
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
    /** @return int[] */
    private function branchIdsOf(\App\Models\SpaBusiness $business): array
    {
        return \App\Models\SpaBranch::where('spa_business_id', $business->id)->pluck('id')->all();
    }

    // What suspending it would reach, shown before the administrator confirms.
    public function suspensionImpact(string $uuid): array
    {
        $business = $this->businessRepository->findForList($uuid);

        return ['data' => \App\Support\Platform\Suspension::impact($this->branchIdsOf($business))];
    }

    public function suspend(User $admin, string $uuid, string $reason, ?Request $request = null)
    {
        $business = $this->businessRepository->findForList($uuid);

        if ($business->verification_status !== VerificationStatus::Verified->value) {
            return response()->json(['message' => 'Only a verified business can be suspended.'], 422);
        }

        $oldValues = $business->only(['verification_status', 'verified_by', 'verified_at', 'suspension_reason']);

        $business = $this->businessRepository->update($business, [
            'verification_status' => VerificationStatus::Suspended->value,
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
        // The clients booked for later are told it is on hold.
        \App\Support\Platform\Suspension::tellClients($this->branchIdsOf($business));

        return new BusinessResource($this->businessRepository->findForList($uuid));
    }

    public function reactivate(User $admin, string $uuid, ?Request $request = null)
    {
        $business = $this->businessRepository->findForList($uuid);

        if ($business->verification_status !== VerificationStatus::Suspended->value) {
            return response()->json(['message' => 'Only a suspended business can be reactivated.'], 422);
        }

        $oldValues = $business->only(['verification_status', 'verified_by', 'verified_at', 'suspension_reason']);

        $business = $this->businessRepository->update($business, [
            'verification_status' => VerificationStatus::Verified->value,
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
        // The clients who were told their booking was on hold are told it is on.
        \App\Support\Platform\Suspension::tellClientsBack($this->branchIdsOf($business));

        return new BusinessResource($this->businessRepository->findForList($uuid));
    }
}

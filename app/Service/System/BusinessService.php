<?php

namespace App\Service\System;

use App\Enums\VerificationStatus;
use App\Http\Resources\System\BusinessDetailResource;
use App\Http\Resources\System\BusinessResource;
use App\Repository\System\BusinessRepository;
use App\Service\Business\CustomerProgramService;

class BusinessService
{
    public function __construct(
        private BusinessRepository $businessRepository,
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
}

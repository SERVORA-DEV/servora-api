<?php

namespace App\Service\System;

use App\Repository\System\BusinessRepository;
use App\Http\Resources\System\BusinessResource;

class BusinessService
{
    private BusinessRepository $businessRepository;

    public function __construct(BusinessRepository $businessRepository)
    {
        $this->businessRepository = $businessRepository;
    }

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
}

<?php

namespace App\Service\System;

use App\Repository\System\BranchRepository;
use App\Http\Resources\SpaBranchResource;

class BranchService
{
    private BranchRepository $branchRepository;

    public function __construct(BranchRepository $branchRepository)
    {
        $this->branchRepository = $branchRepository;
    }

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
}

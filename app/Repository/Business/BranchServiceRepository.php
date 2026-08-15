<?php

namespace App\Repository\Business;

use App\Models\BranchService;

class BranchServiceRepository
{
    // Rows for one service, restricted to the caller's own accessible
    // branches — same SpaBusinessRepository::branchesForUser scoping every
    // other branch-aware lookup uses.
    public function forServiceAndBranches(int $serviceId, array $spaBranchIds)
    {
        return BranchService::with('branch')
            ->where('service_id', $serviceId)
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->get();
    }

    // Wholesale-replace idiom (same as PackageService::syncPackageServices)
    // but upsert rather than delete+recreate: branch_service_facilities FKs
    // to branch_services.id with cascade delete, so recreating rows here
    // would silently orphan any room assignments hanging off the old id.
    public function syncForService(int $serviceId, array $rows): void
    {
        foreach ($rows as $row) {
            BranchService::updateOrCreate(
                ['service_id' => $serviceId, 'spa_branch_id' => $row['spa_branch_id']],
                ['is_available' => $row['is_available'], 'custom_price' => $row['custom_price'] ?? null]
            );
        }
    }
}

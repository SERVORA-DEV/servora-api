<?php

namespace App\Repository\Business;

use App\Models\BranchPackage;

class BranchPackageRepository
{
    // Rows for one package, restricted to the caller's own accessible
    // branches — same SpaBusinessRepository::branchesForUser scoping every
    // other branch-aware lookup uses.
    public function forPackageAndBranches(int $packageId, array $spaBranchIds)
    {
        return BranchPackage::with('branch')
            ->where('package_id', $packageId)
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->get();
    }

    // Wholesale-replace idiom (same as PackageService::syncPackageServices)
    // but upsert rather than delete+recreate — mirrors
    // BranchServiceRepository::syncForService.
    public function syncForPackage(int $packageId, array $rows): void
    {
        foreach ($rows as $row) {
            BranchPackage::updateOrCreate(
                ['package_id' => $packageId, 'spa_branch_id' => $row['spa_branch_id']],
                ['is_available' => $row['is_available'], 'custom_price' => $row['custom_price'] ?? null]
            );
        }
    }
}

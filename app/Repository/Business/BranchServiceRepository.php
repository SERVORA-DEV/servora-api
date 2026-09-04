<?php

namespace App\Repository\Business;

use App\Models\BranchService;

class BranchServiceRepository
{
    // Rows for one service variant, restricted to the caller's own
    // accessible branches — same SpaBusinessRepository::branchesForUser
    // scoping every other branch-aware lookup uses.
    public function forVariantAndBranches(int $serviceVariantId, array $spaBranchIds)
    {
        return BranchService::with('branch')
            ->where('service_variant_id', $serviceVariantId)
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->get();
    }

    // Wholesale-replace idiom (same as PackageService::syncPackageServices)
    // but upsert rather than delete+recreate: branch_service_facilities FKs
    // to branch_services.id with cascade delete, so recreating rows here
    // would silently orphan any room assignments hanging off the old id.
    // Flat bookable-and-priced list for the front-office service picker —
    // see FrontOfficeLookupService.
    public function listBookableForBranches(array $spaBranchIds)
    {
        return BranchService::with('serviceVariant.service')
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->where('is_available', true)
            ->get();
    }

    public function syncForVariant(int $serviceVariantId, array $rows): void
    {
        foreach ($rows as $row) {
            BranchService::updateOrCreate(
                ['service_variant_id' => $serviceVariantId, 'spa_branch_id' => $row['spa_branch_id']],
                [
                    'is_available' => $row['is_available'],
                    'custom_price' => $row['custom_price'] ?? null,
                    'custom_commission' => $row['custom_commission'] ?? null,
                ]
            );
        }
    }
}

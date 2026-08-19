<?php

namespace App\Service\Business;

use App\Models\BranchPackage;
use App\Models\PackageServiceItem;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Repository\Business\BranchPackageRepository;
use App\Repository\Business\PackageRepository;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Http\Resources\PackageResource;

class PackageService
{
    private PackageRepository $packageRepository;
    private SpaBusinessRepository $spaBusinessRepository;
    private SpaBranchRepository $spaBranchRepository;
    private BranchPackageRepository $branchPackageRepository;

    public function __construct(
        PackageRepository $packageRepository,
        SpaBusinessRepository $spaBusinessRepository,
        SpaBranchRepository $spaBranchRepository,
        BranchPackageRepository $branchPackageRepository,
    ) {
        $this->packageRepository = $packageRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->spaBranchRepository = $spaBranchRepository;
        $this->branchPackageRepository = $branchPackageRepository;
    }

    // Same convention as ServiceService::branchIds()/FacilityService::branchIds().
    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
    }

    public function listPackages(User $user, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $collection = $this->packageRepository->paginateForBusiness($business->id, $this->branchIds($user), $perPage);
        return PackageResource::collection($collection);
    }

    /**
     * payload['services'] is [{service_uuid, quantity}, ...] — each uuid must
     * belong to this same business (resolved server-side, 404s otherwise,
     * same guard used everywhere else for cross-resource ownership). Like
     * createService, the new package is auto-enabled at every branch.
     */
    public function createPackage(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $items = $payload['services'] ?? [];
        unset($payload['services']);

        $payload['spa_business_id'] = $business->id;
        $payload['created_by'] = $user->id;
        $payload['is_active'] = $payload['is_active'] ?? true;

        $package = $this->packageRepository->create($payload);

        $this->syncPackageServices($package->id, $business->id, $items);

        foreach ($this->branchIds($user) as $branchId) {
            BranchPackage::create([
                'spa_branch_id' => $branchId,
                'package_id' => $package->id,
                'is_available' => true,
            ]);
        }

        return new PackageResource($package->load('packageServiceItems.serviceVariant.service'));
    }

    public function getPackage(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $model = $this->packageRepository->findByUuidForBusiness($uuid, $business->id, $this->branchIds($user));
        return new PackageResource($model);
    }

    public function updatePackage(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $this->packageRepository->findByUuidForBusiness($uuid, $business->id);

        $items = $payload['services'] ?? null;
        unset($payload['services']);

        $model = $this->packageRepository->update($uuid, $payload);

        if ($items !== null) {
            $this->syncPackageServices($model->id, $business->id, $items);
            $model = $model->load('packageServiceItems.serviceVariant.service');
        }

        return new PackageResource($model);
    }

    public function deletePackage(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $this->packageRepository->findByUuidForBusiness($uuid, $business->id);
        $this->packageRepository->delete($uuid);
        return true;
    }

    // Bulk-replaces this package's branch_packages rows — see
    // ServiceService::updateVariantBranches for the identical reasoning
    // (no per-row uuid to address, frontend always edits the full set).
    // Deliberately independent of the component services' own branch
    // availability — a package may legitimately be staffed/available
    // differently than its individual services, so no cross-check here.
    public function updatePackageBranches(User $user, string $uuid, array $branches)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);
        $package = $this->packageRepository->findByUuidForBusiness($uuid, $business->id);

        $rows = [];
        foreach ($branches as $branch) {
            $spaBranch = $this->spaBranchRepository->findByUuidForBranches($branch['branch_uuid'], $branchIds);

            $rows[] = [
                'spa_branch_id' => $spaBranch->id,
                'is_available' => $branch['is_available'],
                'custom_price' => $branch['custom_price'] ?? null,
            ];
        }

        $this->branchPackageRepository->syncForPackage($package->id, $rows);

        $package = $this->packageRepository->findByUuidForBusiness($uuid, $business->id, $branchIds);
        return new PackageResource($package);
    }

    // Replaces this package's line items wholesale — simpler and safer than
    // diffing, since a package's service list is edited as a whole unit from
    // the form (checkboxes + qty), never one row at a time. Each item pins a
    // specific service variant (duration/price option), not just a service.
    private function syncPackageServices(int $packageId, int $spaBusinessId, array $items): void
    {
        PackageServiceItem::where('package_id', $packageId)->delete();

        foreach (array_values($items) as $index => $item) {
            $variantId = ServiceVariant::where('uuid', $item['service_variant_uuid'])
                ->whereHas('service', fn ($q) => $q->where('spa_business_id', $spaBusinessId))
                ->value('id');

            if (! $variantId) {
                continue;
            }

            PackageServiceItem::create([
                'package_id' => $packageId,
                'service_variant_id' => $variantId,
                'quantity' => $item['quantity'] ?? 1,
                'sort_order' => $index + 1,
            ]);
        }
    }
}

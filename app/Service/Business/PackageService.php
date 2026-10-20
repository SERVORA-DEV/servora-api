<?php

namespace App\Service\Business;

use App\Models\Addon;
use App\Models\Package;
use App\Models\PackageAddon;
use App\Models\PackageServiceItem;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Support\PackagePlan;
use App\Support\ServiceAddon;
use Illuminate\Support\Facades\DB;
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
        private ManagerChangeNotifier $managerChanges,
    ) {
        $this->packageRepository = $packageRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->spaBranchRepository = $spaBranchRepository;
        $this->branchPackageRepository = $branchPackageRepository;
    }

    // Same convention as ServiceService::branchIds()/FacilityService::branchIds().
    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchIdsForUser($user);
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
     * payload['services'] is [{service_uuid, duration_minutes, quantity,
     * addon_uuids}, ...] (App\Support\PackagePlan) — each service and add-on
     * must be this same business's, checked before anything is written. Like
     * createService, the new package starts with no BranchPackage rows — the
     * owner is prompted right after create to explicitly assign branches
     * (saved separately via updatePackageBranches()).
     */
    public function createPackage(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $items = $payload['services'] ?? [];
        unset($payload['services']);

        $rows = $this->plan($business->id, $items);
        if (! is_array($rows)) {
            return $rows;
        }

        $payload['spa_business_id'] = $business->id;
        $payload['created_by'] = $user->id;
        $payload['is_active'] = $payload['is_active'] ?? true;

        $package = DB::transaction(function () use ($payload, $rows) {
            $package = $this->packageRepository->create($payload);
            $this->syncPackageServices($package, $rows);

            return $package;
        });

        return new PackageResource($package->load(Package::contents()));
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

        $existing = $this->packageRepository->findByUuidForBusiness($uuid, $business->id);
        // How it stood before, for telling the managers of the branches that
        // offer it what changed.
        $oldPrice = (float) $existing->default_price;
        $wasActive = (bool) $existing->is_active;

        $items = $payload['services'] ?? null;
        unset($payload['services']);

        $rows = $items !== null ? $this->plan($business->id, $items) : null;
        if ($rows !== null && ! is_array($rows)) {
            return $rows;
        }

        $model = $this->packageRepository->update($uuid, $payload);

        $this->managerChanges->tellCatalog(
            $user, $business->id,
            fn () => $this->branchesOffering($model->id),
            function () use ($model, $oldPrice, $wasActive) {
                $lines = [];
                if ((bool) $model->is_active !== $wasActive) {
                    $lines[] = $model->is_active ? 'switched it back on' : 'switched it off for every branch';
                }
                if ((float) $model->default_price !== $oldPrice) {
                    $lines[] = 'price ' . ManagerChangeNotifier::peso($oldPrice) . ' → ' . ManagerChangeNotifier::peso($model->default_price);
                }

                return $lines ? "changed the package {$model->name} in the catalog: " . implode('; ', $lines) . '.' : null;
            },
        );

        if ($rows !== null) {
            DB::transaction(fn () => $this->syncPackageServices($model, $rows));
            $model = $model->load(Package::contents());
        }

        return new PackageResource($model);
    }

    public function deletePackage(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $package = $this->packageRepository->findByUuidForBusiness($uuid, $business->id);
        $this->packageRepository->delete($uuid);

        $this->managerChanges->tellCatalog(
            $user, $business->id,
            fn () => $this->branchesOffering($package->id),
            "removed the package {$package->name} from the catalog.",
        );

        return true;
    }

    // The branches where this package is on.
    private function branchesOffering(int $packageId): array
    {
        return \App\Models\BranchPackage::where('package_id', $packageId)
            ->where('is_available', true)
            ->distinct()
            ->pluck('spa_branch_id')
            ->all();
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

    // What to save for each line of the package (App\Support\PackagePlan),
    // worked out and checked before anything is written — or the refusal, as
    // a 422. A line is a service of this business with the minutes it runs
    // in the package (the menu's shortest length when none are given), how
    // many, and the add-ons that come with it: each this business's own,
    // switched on, and available for that service (App\Support\ServiceAddon).
    //
    // The older shape — a pinned menu length, service_variant_uuid — is read
    // as that length's service for that length's minutes.
    private function plan(int $spaBusinessId, array $items)
    {
        $withAddons = Package::byService() && Addon::ready();
        $rows = [];

        foreach (array_values($items) as $item) {
            $variant = null;
            if (empty($item['service_uuid']) && ! empty($item['service_variant_uuid'])) {
                $variant = ServiceVariant::where('uuid', $item['service_variant_uuid'])
                    ->whereHas('service', fn ($q) => $q->where('spa_business_id', $spaBusinessId))
                    ->first();
                $service = $variant ? Service::with('variants')->find($variant->service_id) : null;
            } else {
                $service = Service::with('variants')
                    ->where('uuid', $item['service_uuid'] ?? null)
                    ->where('spa_business_id', $spaBusinessId)
                    ->where('is_template', false)
                    ->first();
            }
            if (! $service) {
                return response()->json(['message' => 'One or more of the chosen services are not on your menu.'], 422);
            }
            if (isset($rows[$service->id])) {
                return response()->json(['message' => "{$service->name} is in the package twice — use the quantity for more of the same."], 422);
            }

            $lengths = $service->variants->values();
            $minutes = (int) ($item['duration_minutes'] ?? $variant?->duration_minutes ?? 0);
            if ($minutes <= 0) {
                $at = PackagePlan::closest($lengths->map(fn ($v) => ['minutes' => $v->duration_minutes])->all(), null);
                $minutes = $at === null ? 0 : (int) $lengths[$at]->duration_minutes;
            }
            if ($minutes <= 0) {
                return response()->json(['message' => "Give {$service->name} its minutes in this package."], 422);
            }

            $addons = [];
            foreach ($withAddons ? array_unique($item['addon_uuids'] ?? []) : [] as $addonUuid) {
                $addon = Addon::where('uuid', $addonUuid)->where('spa_business_id', $spaBusinessId)->with('services:id')->first();
                if (! $addon) {
                    return response()->json(['message' => 'One of the chosen add-ons is no longer on your menu.'], 422);
                }
                if (! $addon->is_active) {
                    return response()->json(['message' => "{$addon->name} is switched off — switch it on, or take it out of the package."], 422);
                }
                if (! ServiceAddon::appliesTo($addon->rule(), $service->category, $service->id)) {
                    return response()->json(['message' => "{$addon->name} isn't offered with {$service->name}."], 422);
                }
                $addons[] = $addon;
            }

            $rows[$service->id] = [
                'service' => $service,
                // The menu length nearest its minutes: what it is stored as
                // until packages name services (the migration).
                'variant' => $variant ?? (($at = PackagePlan::closest($lengths->map(fn ($v) => ['minutes' => $v->duration_minutes])->all(), $minutes)) === null ? null : $lengths[$at]),
                'minutes' => $minutes,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'price' => isset($item['price']) && $item['price'] !== '' ? round((float) $item['price'], 2) : null,
                'addons' => $addons,
            ];
        }

        return array_values($rows);
    }

    // Replaces what is in the package wholesale — simpler and safer than
    // diffing, since it is edited as a whole from the form — and sets the
    // package's duration to the total of its parts.
    private function syncPackageServices(Package $package, array $rows): void
    {
        $byService = Package::byService();
        PackageServiceItem::where('package_id', $package->id)->delete();
        if ($byService) {
            PackageAddon::where('package_id', $package->id)->delete();
        }

        foreach ($rows as $index => $row) {
            if (! $byService) {
                // Before the migration a line can only be a menu length.
                if ($row['variant']) {
                    PackageServiceItem::create([
                        'package_id' => $package->id,
                        'service_variant_id' => $row['variant']->id,
                        'quantity' => $row['quantity'],
                        'sort_order' => $index + 1,
                    ]);
                }
                continue;
            }

            PackageServiceItem::create([
                'package_id' => $package->id,
                'service_id' => $row['service']->id,
                'duration_minutes' => $row['minutes'],
                'quantity' => $row['quantity'],
                'sort_order' => $index + 1,
            ] + (Package::hasLinePrice() ? ['price' => $row['price'] ?? null] : []));
            foreach ($row['addons'] as $addon) {
                PackageAddon::create(['package_id' => $package->id, 'addon_id' => $addon->id, 'service_id' => $row['service']->id]);
            }
        }

        if ($byService) {
            $package->update(['duration_minutes' => PackagePlan::totalMinutes(
                array_map(fn ($row) => ['minutes' => $row['minutes'], 'quantity' => $row['quantity']], $rows),
                array_merge(...array_map(fn ($row) => array_map(fn ($addon) => $addon->duration_minutes, $row['addons']), $rows ?: [['addons' => []]])),
            ) ?: null]);
        }
    }
}

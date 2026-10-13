<?php

namespace App\Service\Business;

use App\Models\BranchPackage;
use App\Models\BranchService;
use App\Models\Package;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Support\AppCache;
use Illuminate\Support\Facades\DB;

// What one branch offers — which services (per length) and packages, and at
// what price — saved together. The picker in Branch Settings collects every
// change and sends them once, instead of one request per switch
// (ServiceService::updateVariantBranches / PackageService::updatePackageBranches
// still exist for the screens that change a single item).
//
// One write per table: over a slow database connection a request per row, or
// even a query per row, is what made saving time out.
class BranchOfferingService
{
    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private SpaBranchRepository $spaBranchRepository,
        private ManagerChangeNotifier $managerChanges,
    ) {
    }

    /**
     * @param  array<int, array{variant_uuid: string, is_available: bool, custom_price?: float|null}>  $services
     * @param  array<int, array{package_uuid: string, is_available: bool, custom_price?: float|null}>  $packages
     */
    public function save(User $user, string $branchUuid, array $services, array $packages)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // 404s unless the branch is one of the caller's own (an owner's
        // branches, or a manager's one branch).
        $branch = $this->spaBranchRepository->findByUuidForBranches(
            $branchUuid,
            $this->spaBusinessRepository->branchIdsForUser($user),
        );

        // Only this business's own services and packages; anything else in
        // the payload is refused rather than skipped.
        $variantIds = $services
            ? ServiceVariant::whereIn('uuid', array_column($services, 'variant_uuid'))
                ->whereHas('service', fn ($q) => $q->where('spa_business_id', $business->id))
                ->pluck('id', 'uuid')
            : collect();
        $packageIds = $packages
            ? Package::whereIn('uuid', array_column($packages, 'package_uuid'))
                ->where('spa_business_id', $business->id)
                ->pluck('id', 'uuid')
            : collect();

        abort_if(
            $variantIds->count() !== count($services) || $packageIds->count() !== count($packages),
            404,
            'One of these services or packages is no longer in your catalog. Reload the page and try again.',
        );

        $row = fn (array $item) => [
            'spa_branch_id' => $branch->id,
            'is_available' => (bool) $item['is_available'],
            'custom_price' => $item['custom_price'] ?? null,
            // A row removed earlier comes back rather than clashing with the
            // unique (branch, item) index.
            'deleted_at' => null,
        ];
        $update = ['is_available', 'custom_price', 'deleted_at'];

        DB::transaction(function () use ($services, $packages, $variantIds, $packageIds, $row, $update) {
            if ($services) {
                BranchService::upsert(
                    array_map(fn ($s) => $row($s) + ['service_variant_id' => $variantIds[$s['variant_uuid']]], $services),
                    ['spa_branch_id', 'service_variant_id'],
                    $update,
                );
            }
            if ($packages) {
                BranchPackage::upsert(
                    array_map(fn ($p) => $row($p) + ['package_id' => $packageIds[$p['package_uuid']]], $packages),
                    ['spa_branch_id', 'package_id'],
                    $update,
                );
            }
        });

        // upsert() fires no model events, so CacheInvalidation never sees
        // these rows — clear what clients see of this business here.
        AppCache::bumpBusiness($business->id);

        $this->managerChanges->tell(
            $user, $branch, ManagerChangeNotifier::OFFERINGS, 'Branch Offerings Changed',
            fn () => "changed what {$branch->branch_name} offers: " . $this->inWords($services, $packages) . '.',
        );

        $saved = fn (array $item, string $key) => [
            $key => $item[$key],
            'is_available' => (bool) $item['is_available'],
            'custom_price' => isset($item['custom_price']) ? (float) $item['custom_price'] : null,
        ];

        return response()->json(['data' => [
            'branch_uuid' => $branch->uuid,
            'branch_name' => $branch->branch_name,
            'services' => array_map(fn ($s) => $saved($s, 'variant_uuid'), $services),
            'packages' => array_map(fn ($p) => $saved($p, 'package_uuid'), $packages),
        ]]);
    }

    // The saved rows in words, for the owner's notification: "Swedish Massage
    // 1h 30min now ₱1,150; Hilot 1h switched off". The first few, then a count.
    private function inWords(array $services, array $packages): string
    {
        $variants = $services
            ? ServiceVariant::with('service:id,name')->whereIn('uuid', array_column($services, 'variant_uuid'))->get()->keyBy('uuid')
            : collect();
        $packageNames = $packages
            ? Package::whereIn('uuid', array_column($packages, 'package_uuid'))->pluck('name', 'uuid')
            : collect();

        $length = function (?int $minutes): string {
            if (! $minutes) {
                return '';
            }
            $hours = intdiv($minutes, 60);
            $rest = $minutes % 60;

            return trim(($hours ? "{$hours}h" : '') . ($rest ? " {$rest}min" : ''));
        };
        $state = function (array $item): string {
            if (! $item['is_available']) {
                return 'switched off';
            }
            if (! isset($item['custom_price'])) {
                return 'on at the standard price';
            }
            $price = (float) $item['custom_price'];

            return 'now ₱' . number_format($price, fmod($price, 1) ? 2 : 0);
        };

        $lines = [];
        foreach ($services as $item) {
            $variant = $variants[$item['variant_uuid']] ?? null;
            $lines[] = trim(($variant?->service?->name ?? 'A service') . ' ' . $length($variant?->duration_minutes)) . ' ' . $state($item);
        }
        foreach ($packages as $item) {
            $lines[] = ($packageNames[$item['package_uuid']] ?? 'A package') . ' ' . $state($item);
        }

        $shown = array_slice($lines, 0, 4);
        $more = count($lines) - count($shown);

        return implode('; ', $shown) . ($more > 0 ? "; and {$more} more" : '');
    }
}

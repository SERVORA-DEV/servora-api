<?php

namespace App\Service\Business;

use App\Models\BranchService;
use App\Models\Service;
use App\Models\SpaBranch;
use App\Models\Staff;
use App\Repository\Business\SpaBranchRepository;
use App\Service\Client\BookingPolicy;
use Illuminate\Support\Collection;

// The owner's home service checklist (web: Business Defaults → Offer home
// service): for each branch, what HomeServiceRules needs before a client can
// book a home visit there, and how much of it is done.
//
//   • the branch offers it (Business Defaults, or the branch's own rule);
//   • the branch has its pin on the map (the service area is measured from it);
//   • it has active services ticked "Available for home service" — counted
//     among the ones this branch offers (publicServices: active and on here);
//   • it has active staff who perform services, ticked "Available for home
//     service" (Staff::scopeForHomeService).
//
// "bookable" is HomeServiceRules::offer()'s answer, worked out from the same
// rows so the list and the counts can't disagree. A handful of queries for the
// whole business, not one per branch.
class HomeServiceReadiness
{
    /** Most names sent back for a list of what is still missing. */
    private const NAMES = 60;

    /**
     * @param  Collection<int, SpaBranch>  $branches  the branches the user can see
     */
    public function present(int $businessId, Collection $branches): array
    {
        $staffSetting = Staff::hasHomeService();
        $ids = $branches->pluck('id')->all();

        // The business's active services: the tick is on the service, for every branch.
        $services = Service::where('spa_business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'home_service_available']);

        // Which of those each branch offers (active, and switched on at that branch).
        $offered = $ids
            ? SpaBranchRepository::publicServices(BranchService::whereIn('spa_branch_id', $ids))
                ->with('serviceVariant:id,service_id')
                ->get(['id', 'spa_branch_id', 'service_variant_id'])
                ->groupBy('spa_branch_id')
                ->map(fn ($rows) => $rows->pluck('serviceVariant.service_id')->filter()->unique()->values())
            : collect();

        $staff = $ids
            ? Staff::whereIn('spa_branch_id', $ids)
                ->where('role', 'therapist')
                ->where('status', 'active')
                ->orderBy('first_name')->orderBy('last_name')
                ->get(array_merge(['id', 'uuid', 'first_name', 'last_name', 'role', 'spa_branch_id'], $staffSetting ? ['home_service_available'] : []))
                ->groupBy('spa_branch_id')
            : collect();

        $homeServiceIds = $services->where('home_service_available', true)->pluck('id')->all();

        return [
            // False until staff can be ticked (migration): then everyone counts.
            'staff_setting' => $staffSetting,
            'services' => [
                'active' => $services->count(),
                'home' => count($homeServiceIds),
                'missing' => $services->where('home_service_available', false)->take(self::NAMES)
                    ->map(fn (Service $s) => ['id' => $s->uuid, 'name' => $s->name])->values()->all(),
            ],
            'branches' => $branches
                ->sortBy('branch_name', SORT_NATURAL | SORT_FLAG_CASE)
                ->map(function (SpaBranch $branch) use ($offered, $staff, $homeServiceIds) {
                    $policy = BookingPolicy::for($branch);
                    $own = (array) ($branch->booking_overrides ?? []);
                    $hasPin = $branch->latitude !== null && $branch->longitude !== null;

                    $here = $offered->get($branch->id, collect());
                    $people = $staff->get($branch->id, collect());
                    $goOut = $people->filter(fn (Staff $s) => $s->takesHomeService());

                    $on = (bool) $policy['home_service_enabled'];

                    return [
                        'id' => $branch->uuid,
                        'name' => $branch->branch_name,
                        'offered' => $on,
                        // This branch has its own on/off rule (Branch Settings → Booking & Policies).
                        'own_rule' => array_key_exists('home_service_enabled', $own),
                        'has_pin' => $hasPin,
                        // HomeServiceRules::offer(): on, pinned, and someone to send.
                        'bookable' => $on && $hasPin && $goOut->isNotEmpty(),
                        'fee' => (float) $policy['home_service_fee'],
                        'radius_km' => (int) $policy['home_service_radius_km'],
                        'min_minutes' => (int) $policy['home_service_min_minutes'],
                        'services' => [
                            'offered' => $here->count(),
                            'home' => $here->intersect($homeServiceIds)->count(),
                        ],
                        'staff' => [
                            'active' => $people->count(),
                            'home' => $goOut->count(),
                            'missing' => $people->reject(fn (Staff $s) => $s->takesHomeService())->take(self::NAMES)
                                ->map(fn (Staff $s) => ['id' => $s->uuid, 'name' => trim("{$s->first_name} {$s->last_name}")])
                                ->values()->all(),
                        ],
                    ];
                })
                ->values()
                ->all(),
        ];
    }
}

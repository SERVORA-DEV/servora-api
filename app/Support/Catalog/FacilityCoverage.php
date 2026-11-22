<?php

namespace App\Support\Catalog;

use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Whether a branch has somewhere to do a facility-based service (a sauna): a facility there
 * that is switched on (in rotation; one under maintenance still counts, it is only for now)
 * with that service turned on — "An appointment can only go in a facility that has its
 * service on" (Facilities page). One that no facility handles isn't offered in the app and
 * can't be booked there.
 */
final class FacilityCoverage
{
    /** The services (ids) that some facility at the branch has turned on. */
    public static function handledAt(int $branchId): array
    {
        return self::handledByBranch([$branchId])[$branchId] ?? [];
    }

    /**
     * The same for several branches in one query (the front desk's menu): branch id => service ids.
     *
     * @param  array<int, int>  $branchIds
     * @return array<int, array<int, int>>
     */
    public static function handledByBranch(array $branchIds): array
    {
        if (! $branchIds) {
            return [];
        }

        return DB::table('facility_services')
            ->join('facilities', 'facilities.id', '=', 'facility_services.facility_id')
            ->whereIn('facilities.spa_branch_id', $branchIds)
            ->where('facilities.is_available', true)
            ->whereNull('facilities.deleted_at')
            ->distinct()
            ->get(['facilities.spa_branch_id', 'facility_services.service_id'])
            ->groupBy('spa_branch_id')
            ->map(fn ($rows) => $rows->pluck('service_id')->map(fn ($id) => (int) $id)->values()->all())
            ->mapWithKeys(fn ($ids, $branchId) => [(int) $branchId => $ids])
            ->all();
    }

    /** Whether a service can be offered at a branch, given what is handled there ($handled). */
    public static function offers(?string $serviceKind, int $serviceId, array $handled): bool
    {
        return ServiceKind::of($serviceKind) !== ServiceKind::FACILITY || in_array($serviceId, $handled, true);
    }

    /**
     * The facility-based services among these (ids) that no facility at the branch handles.
     *
     * @return Collection<int, Service>
     */
    public static function unhandled(int $branchId, iterable $serviceIds): Collection
    {
        $ids = collect($serviceIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $handled = self::handledAt($branchId);

        return Service::whereIn('id', $ids)->get(['id', 'name', 'service_type'])
            ->reject(fn (Service $s) => self::offers($s->service_type, $s->id, $handled))
            ->values();
    }
}

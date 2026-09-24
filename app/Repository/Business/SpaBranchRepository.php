<?php

namespace App\Repository\Business;

use App\Models\BranchPackage;
use App\Models\BranchService;
use App\Models\SpaBranch;
use App\Models\Staff;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class SpaBranchRepository
{
    public function paginate(int $perPage = 15)
    {
        return SpaBranch::latest()->paginate($perPage);
    }

    // Backs GET /spas/nearby — only branches a client should ever be able to
    // browse to (Verified + Active, with a pin actually set). Distance is
    // computed in SQL via the Haversine formula so ordering/filtering by it
    // doesn't require pulling every branch into PHP first.
    //
    // The radius filter has to happen one level up, in a subquery wrapper:
    // distance_km is a select alias, and PostgreSQL only resolves output
    // aliases in GROUP BY and ORDER BY — never in WHERE or HAVING.
    public function nearby(float $lat, float $lng, float $radiusKm, int $limit): Collection
    {
        $withDistance = SpaBranch::query()
            ->select('spa_branches.*')
            ->selectRaw(
                '(6371 * acos(least(1, greatest(-1,
                    cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?))
                    + sin(radians(?)) * sin(radians(latitude))
                )))) as distance_km',
                [$lat, $lng, $lat]
            )
            ->where('verification_status', 'Verified')
            ->where('operating_status', 'Active')
            ->where('listing_visible', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        // The extra relations feed Explore's filters (open now, category,
        // starting price) and are constrained exactly like the branch-detail
        // lookups below, so a browse card can't advertise a service or price
        // the details screen then doesn't list. Eager-loaded, so a page of up
        // to 50 branches is still a fixed handful of queries.
        return SpaBranch::query()
            ->fromSub($withDistance, 'spa_branches')
            ->where('distance_km', '<=', $radiusKm)
            ->with([
                'business',
                'schedules',
                'coverPhoto',
                'branchServices' => fn ($q) => self::publicServices($q)->with('serviceVariant.service'),
                'branchPackages' => fn ($q) => self::publicPackages($q)->with('package'),
            ])
            ->orderBy('distance_km')
            ->limit($limit)
            ->get();
    }

    // Backs GET /spas/{uuid} — same Verified+Active+listed guard as nearby(), and
    // 404s (not 403s) for anything that doesn't match, matching the
    // anti-enumeration posture of findByUuidForBranches below: a stranger
    // can't tell "wrong uuid" apart from "real branch, just not public yet".
    public function publicFindByUuid(string $uuid): SpaBranch
    {
        return SpaBranch::where('uuid', $uuid)
            ->where('verification_status', 'Verified')
            ->where('operating_status', 'Active')
            ->where('listing_visible', true)
            ->with(['business', 'schedules', 'coverPhoto', 'photos'])
            ->firstOrFail();
    }

    // Only services the owner has both kept active business-wide (Service)
    // and turned on for this specific branch (BranchService.is_available) —
    // either flag off means a stranger shouldn't see it as bookable here.
    public function publicServicesForBranch(int $branchId): Collection
    {
        return self::publicServices(BranchService::where('spa_branch_id', $branchId))
            ->with('serviceVariant.service')
            ->get();
    }

    // Same active-at-both-levels guard as publicServicesForBranch, for
    // packages.
    public function publicPackagesForBranch(int $branchId): Collection
    {
        return self::publicPackages(BranchPackage::where('spa_branch_id', $branchId))
            ->with('package')
            ->get();
    }

    // The "a stranger may see this" rule for branch services and packages,
    // shared by the branch-detail lookups above and nearby()'s eager loads
    // so the two can't disagree. Takes and returns a query builder (or a
    // relation's), since nearby() applies it inside a `with()` constraint.
    private static function publicServices($query)
    {
        return $query
            ->where('is_available', true)
            ->whereHas('serviceVariant.service', fn ($q) => $q->where('is_active', true));
    }

    private static function publicPackages($query)
    {
        return $query
            ->where('is_available', true)
            ->whereHas('package', fn ($q) => $q->where('is_active', true));
    }

    // Name + role only — Staff has no public resource yet and profile_photo
    // isn't exposed anywhere in the app, so a browse card can't show one.
    //
    // `id` rides along unemitted: it never reaches a response (both consuming
    // resources list their keys explicitly), but
    // SpaBranchService::publicTherapistAvailability needs it to look each
    // therapist's shifts and bookings up, and a partial select leaves it null
    // rather than erroring.
    public function publicTherapistsForBranch(int $branchId): Collection
    {
        return Staff::where('spa_branch_id', $branchId)
            ->where('role', 'therapist')
            ->where('status', 'active')
            ->get(['id', 'uuid', 'first_name', 'last_name', 'role']);
    }

    // Scoped listing for the owner's own "Branches" page — paginate() above
    // has no business filter, so every owner would see every business's
    // branches.
    public function paginateForBusiness(int $spaBusinessId, int $perPage = 15)
    {
        return SpaBranch::where('spa_business_id', $spaBusinessId)->latest()->paginate($perPage);
    }

    // Scoped to a specific set of branch ids — what listSpaBranch() actually
    // uses now (via SpaBusinessRepository::branchesForUser), since a manager
    // must only ever see their own single branch, not the whole business's
    // like paginateForBusiness() above returns.
    public function paginateForBranches(array $spaBranchIds, int $perPage = 15)
    {
        return SpaBranch::whereIn('id', $spaBranchIds)->latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return SpaBranch::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return SpaBranch::where('uuid', $uuid)->firstOrFail();
    }

    // Scoped lookup used by show/update/destroy — 404s instead of returning
    // another business's branch just because its uuid was guessed/known.
    public function findByUuidForBusiness(string $uuid, int $spaBusinessId)
    {
        return SpaBranch::where('uuid', $uuid)
            ->where('spa_business_id', $spaBusinessId)
            ->firstOrFail();
    }

    // Same guard as findByUuidForBusiness, scoped to specific branch ids —
    // what getSpaBranch() (the only manager-reachable single-branch lookup;
    // create/update/delete stay owner-only per routes/api.php) uses now, so
    // a manager 404s outside their own branch instead of being able to view
    // any branch in the business.
    public function findByUuidForBranches(string $uuid, array $spaBranchIds)
    {
        return SpaBranch::where('uuid', $uuid)
            ->whereIn('id', $spaBranchIds)
            ->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return SpaBranch::where($field, $value)->firstOrFail();
    }

    public function update(string $uuid, array $payload)
    {
        $model = $this->findByUuid($uuid);
        $model->update($payload);
        return $model;
    }

    public function delete(string $uuid)
    {
        $model = $this->findByUuid($uuid);
        return $model->delete();
    }

    public function restore(string $uuid)
    {
        $model = SpaBranch::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $model->restore();
        return $model;
    }
}
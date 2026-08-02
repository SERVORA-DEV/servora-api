<?php

namespace App\Repository\Business;

use App\Models\BranchSchedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;

// Day order matches BranchSchedule's day_of_week enum — used to sort a
// branch's week into calendar order rather than insertion/creation order.
const DAY_OF_WEEK_ORDER = [
    'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
];

class BranchScheduleRepository
{
    public function paginate(int $perPage = 15)
    {
        return BranchSchedule::latest()->paginate($perPage);
    }

    // All of one branch's schedule rows (at most 7 — one per day), in
    // calendar order. Caller is responsible for the branch-ownership check;
    // this is a plain FK filter.
    public function allForBranch(int $spaBranchId)
    {
        return BranchSchedule::where('spa_branch_id', $spaBranchId)
            ->orderByRaw('FIELD(day_of_week, "' . implode('","', DAY_OF_WEEK_ORDER) . '")')
            ->get();
    }

    // Matches the (spa_branch_id, day_of_week) unique index — checked before
    // create() so a duplicate day comes back as a clean 422 instead of an
    // uncaught DB integrity exception.
    public function existsForBranchDay(int $spaBranchId, string $dayOfWeek): bool
    {
        return BranchSchedule::where('spa_branch_id', $spaBranchId)
            ->where('day_of_week', $dayOfWeek)
            ->exists();
    }

    public function create(array $payload)
    {
        return BranchSchedule::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return BranchSchedule::where('uuid', $uuid)->firstOrFail();
    }

    // Scoped lookup used by show/update/destroy — 404s instead of returning
    // another business's schedule row just because its uuid was
    // guessed/known. Joins through the owning branch since schedules don't
    // carry spa_business_id directly.
    public function findByUuidForBusiness(string $uuid, int $spaBusinessId)
    {
        return BranchSchedule::where('uuid', $uuid)
            ->whereHas('branch', fn ($query) => $query->where('spa_business_id', $spaBusinessId))
            ->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return BranchSchedule::where($field, $value)->firstOrFail();
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
        $model = BranchSchedule::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $model->restore();
        return $model;
    }
}
<?php

namespace App\Repository\Business;

use App\Models\BranchSchedule;
use App\Support\DayOfWeek;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

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
            ->orderByRaw(DayOfWeek::calendarOrder(), DayOfWeek::ORDER)
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

    // A branch's whole week in one statement: a day that already has a row
    // is updated, a day that doesn't gets one — keyed on the same
    // (spa_branch_id, day_of_week) unique index. Seven separate saves over a
    // slow connection is what made saving hours time out.
    //
    // upsert() fires no model events; the caller clears the cache.
    public function upsertWeek(int $spaBranchId, array $days): void
    {
        BranchSchedule::upsert(
            array_map(fn (array $day) => [
                // Used only for a new row; an existing day keeps its uuid.
                'uuid' => (string) Str::uuid7(),
                'spa_branch_id' => $spaBranchId,
                'day_of_week' => $day['day_of_week'],
                'opening_time' => $day['opening_time'] ?? null,
                'closing_time' => $day['closing_time'] ?? null,
                'is_closed' => (bool) ($day['is_closed'] ?? false),
            ], $days),
            ['spa_branch_id', 'day_of_week'],
            ['opening_time', 'closing_time', 'is_closed'],
        );
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

    // Same guard as findByUuidForBusiness, but scoped to a specific set of
    // branch ids rather than a whole business — what BranchScheduleService
    // uses for a manager, whose access is their own staff record's branch
    // rather than every branch of the business (mirrors
    // FacilityRepository::findByUuidForBranches).
    public function findByUuidForBranches(string $uuid, array $spaBranchIds)
    {
        return BranchSchedule::where('uuid', $uuid)
            ->whereIn('spa_branch_id', $spaBranchIds)
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
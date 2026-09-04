<?php

namespace App\Service\Business;

use App\Http\Resources\StaffScheduleResource;
use App\Models\User;
use App\Repository\Business\StaffRepository;
use App\Repository\Business\StaffScheduleRepository;
use App\Repository\SpaBusinessRepository;
use Illuminate\Support\Facades\DB;

class StaffScheduleService
{
    private StaffScheduleRepository $staffScheduleRepository;
    private StaffRepository $staffRepository;
    private SpaBusinessRepository $spaBusinessRepository;

    public function __construct(
        StaffScheduleRepository $staffScheduleRepository,
        StaffRepository $staffRepository,
        SpaBusinessRepository $spaBusinessRepository,
    ) {
        $this->staffScheduleRepository = $staffScheduleRepository;
        $this->staffRepository = $staffRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
    }

    // Same branch-scoping convention as StaffService::branchIds — a manager
    // only ever reaches their own branch's staff.
    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
    }

    public function getSchedule(User $user, string $staffUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $staff = $this->staffRepository->findByUuidForBranches($staffUuid, $this->branchIds($user));

        return StaffScheduleResource::collection($this->staffScheduleRepository->currentForStaff($staff->id));
    }

    // Days omitted from the payload are left/made "not set" (no row at all) —
    // a whole-set replace, so omission is meaningful, same as
    // StaffService::updateServices' empty array meaning "no restrictions
    // configured". is_day_off always wins over any shift/break times the
    // client sends for that day, matching how
    // AppointmentAvailabilityService::staffMatchesSchedule already treats
    // is_day_off as short-circuiting those checks.
    public function updateSchedule(User $user, string $staffUuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $staff = $this->staffRepository->findByUuidForBranches($staffUuid, $this->branchIds($user));

        $rows = collect($payload['days'] ?? [])->map(function (array $day) {
            $isDayOff = (bool) ($day['is_day_off'] ?? false);

            return [
                'day_of_week' => $day['day_of_week'],
                'is_day_off' => $isDayOff,
                'start_time' => $isDayOff ? null : ($day['start_time'] ?? null),
                'end_time' => $isDayOff ? null : ($day['end_time'] ?? null),
                'break_start' => $isDayOff ? null : ($day['break_start'] ?? null),
                'break_end' => $isDayOff ? null : ($day['break_end'] ?? null),
            ];
        })->all();

        DB::transaction(fn () => $this->staffScheduleRepository->replaceCurrentForStaff($staff->id, $rows));

        return StaffScheduleResource::collection($this->staffScheduleRepository->currentForStaff($staff->id));
    }
}

<?php

namespace App\Service\Business;

use App\Http\Resources\StaffTimeOffResource;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Repository\Business\StaffRepository;
use App\Repository\SpaBusinessRepository;

// Employees → Schedule → Time off. Same branch scoping as StaffScheduleService:
// a manager only reaches their own branch's staff.
class StaffTimeOffService
{
    public function __construct(
        private StaffRepository $staffRepository,
        private SpaBusinessRepository $spaBusinessRepository,
    ) {
    }

    private function notReady()
    {
        return response()->json(['message' => 'Time off isn’t switched on yet. Ask your admin to update the system.'], 422);
    }

    private function staffFor(User $user, string $staffUuid)
    {
        return $this->staffRepository->findByUuidForBranches($staffUuid, $this->spaBusinessRepository->branchIdsForUser($user));
    }

    public function list(User $user, string $staffUuid)
    {
        if (! StaffTimeOff::ready()) {
            return response()->json(['data' => [], 'ready' => false]);
        }

        $staff = $this->staffFor($user, $staffUuid);

        return StaffTimeOffResource::collection(
            StaffTimeOff::where('staff_id', $staff->id)->orderBy('starts_on')->get()
        )->additional(['ready' => true]);
    }

    public function create(User $user, string $staffUuid, array $payload)
    {
        if (! StaffTimeOff::ready()) {
            return $this->notReady();
        }

        $staff = $this->staffFor($user, $staffUuid);

        $row = StaffTimeOff::create([
            'staff_id' => $staff->id,
            'type' => $payload['type'],
            'starts_on' => $payload['starts_on'],
            'ends_on' => $payload['ends_on'],
            'note' => $payload['note'] ?? null,
            'created_by_user_id' => $user->id,
        ]);

        return (new StaffTimeOffResource($row))->response()->setStatusCode(201);
    }

    public function delete(User $user, string $staffUuid, string $timeOffUuid)
    {
        if (! StaffTimeOff::ready()) {
            return $this->notReady();
        }

        $staff = $this->staffFor($user, $staffUuid);

        StaffTimeOff::where('staff_id', $staff->id)->where('uuid', $timeOffUuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Time off removed.']);
    }
}

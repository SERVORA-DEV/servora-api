<?php

namespace App\Service\Business;

use App\Http\Resources\StaffTimeOffResource;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Repository\Business\StaffRepository;
use App\Repository\SpaBusinessRepository;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        // Saved even over booked days (an emergency can't wait); the bookings it lands
        // on come back so they can be given to someone else.
        return (new StaffTimeOffResource($row))
            ->additional(['affected' => $this->affected(collect([$row]), [$staff->id => trim("{$staff->first_name} {$staff->last_name}")])])
            ->response()->setStatusCode(201);
    }

    /**
     * Everyone's time off touching [$from, $to] at the branches this login works,
     * and the bookings it lands on: the Shifts schedule, the staff calendar, the
     * dashboards. Front desk and management read the same list.
     */
    public function range(User $user, string $from, string $to)
    {
        if (! StaffTimeOff::ready()) {
            return response()->json(['data' => [], 'affected' => [], 'ready' => false]);
        }

        $staff = Staff::whereIn('spa_branch_id', $this->spaBusinessRepository->branchIdsForUser($user))
            ->get(['id', 'uuid', 'first_name', 'last_name']);
        $names = $staff->mapWithKeys(fn ($s) => [$s->id => trim("{$s->first_name} {$s->last_name}")])->all();
        $uuids = $staff->pluck('uuid', 'id')->all();

        $rows = StaffTimeOff::whereIn('staff_id', array_keys($uuids))
            ->where('starts_on', '<=', $to)
            ->where('ends_on', '>=', $from)
            ->orderBy('starts_on')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (StaffTimeOff $r) => [
                'id' => $r->uuid,
                'staff_id' => $uuids[$r->staff_id] ?? null,
                'staff_name' => $names[$r->staff_id] ?? '',
                'type' => $r->type,
                'label' => StaffTimeOff::LABELS[$r->type] ?? 'Time off',
                'starts_on' => $r->starts_on->toDateString(),
                'ends_on' => $r->ends_on->toDateString(),
                'note' => $r->note,
            ])->values(),
            'affected' => $this->affected($rows, $names, $uuids),
            'ready' => true,
        ]);
    }

    /**
     * Upcoming bookings (not yet served) whose staff member is on time off that day,
     * from today on. Only read: nothing about the booking is changed.
     *
     * @param  Collection<int, StaffTimeOff>  $rows
     * @param  array<int, string>  $names  staff id => name
     * @param  array<int, string>  $uuids  staff id => uuid
     */
    private function affected(Collection $rows, array $names, array $uuids = []): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $today = Carbon::today()->toDateString();
        $from = max($today, $rows->min(fn ($r) => $r->starts_on->toDateString()));
        $to = $rows->max(fn ($r) => $r->ends_on->toDateString());
        if ($from > $to) {
            return [];
        }

        $found = DB::table('therapist_assignments as ta')
            ->join('appointment_services as s', 's.id', '=', 'ta.appointment_service_id')
            ->join('appointments as a', 'a.id', '=', 's.appointment_id')
            ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
            ->leftJoin('spa_branches as br', 'br.id', '=', 'a.spa_branch_id')
            ->whereIn('ta.staff_id', $rows->pluck('staff_id')->unique()->all())
            ->where('ta.assignment_status', 'Assigned')
            ->whereIn('a.status', ['scheduled', 'checked_in'])
            ->whereNull('a.deleted_at')
            ->whereBetween('a.appointment_date', [$from, $to])
            ->orderBy('a.appointment_date')
            ->orderBy('a.appointment_time')
            ->get(['a.uuid', 'a.appointment_date', 'a.appointment_time', 'ta.staff_id', 'c.first_name', 'c.last_name', 'br.uuid as branch_uuid']);

        $out = [];
        foreach ($found as $f) {
            $date = substr((string) $f->appointment_date, 0, 10);
            $span = $rows->first(fn ($r) => (int) $r->staff_id === (int) $f->staff_id
                && $r->starts_on->toDateString() <= $date && $r->ends_on->toDateString() >= $date);
            if (! $span || isset($out[$f->uuid . ':' . $f->staff_id])) {
                continue;
            }
            $out[$f->uuid . ':' . $f->staff_id] = [
                'appointment_id' => $f->uuid,
                'date' => $date,
                'time' => $f->appointment_time ? substr((string) $f->appointment_time, 0, 5) : null,
                'client_name' => trim(($f->first_name ?? '') . ' ' . ($f->last_name ?? '')) ?: 'Client',
                'staff_id' => $uuids[$f->staff_id] ?? null,
                'staff_name' => $names[$f->staff_id] ?? '',
                'label' => StaffTimeOff::LABELS[$span->type] ?? 'Time off',
                'branch_id' => $f->branch_uuid,
            ];
        }

        return array_values($out);
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

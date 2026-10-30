<?php

namespace App\Service\Business;

use App\Models\BranchService;
use App\Models\Facility;
use App\Models\ServiceVariant;
use App\Models\Staff;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Support\RotationTurn;
use App\Support\ServiceKind;
use Carbon\Carbon;

// "Who and what can do this service at this time?" — the front desk's Check Availability.
// Nothing new is decided here: it asks the same questions the server asks when staff or a room
// is actually assigned (qualification, shift, existing bookings, room set-up, maintenance), for
// every therapist and facility at once, so what it offers is what an assignment will accept.
//
//  - Staff are advisory in this app (reservations aren't time-blocked), so a clash with another
//    booking is reported as `busy` with who and when, not hidden.
//  - Attendance only matters for today: someone who hasn't timed in can't take a service that
//    starts now, but a later one is judged by their shift. Another day is judged by shift alone.
//  - Rotation order and "next up" come from TherapistQueueCalculator, the one source of turn order.
class AvailabilityCheckService
{
    /** A service starting within this many minutes of now needs its staff already on the floor. */
    private const IMMINENT_MINUTES = 15;

    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private AppointmentAvailabilityService $availability,
        private TherapistQueueCalculator $rotation,
    ) {
    }

    public function check(User $user, string $variantUuid, string $date, string $time)
    {
        $branchId = $this->spaBusinessRepository->branchIdsForUser($user)[0] ?? null;
        if (! $branchId) {
            return response()->json(['message' => 'No branch found for this account.'], 422);
        }

        $variant = ServiceVariant::where('uuid', $variantUuid)->with('service')->first();
        if (! $variant || ! BranchService::where('service_variant_id', $variant->id)->where('spa_branch_id', $branchId)->exists()) {
            return response()->json(['message' => 'That service is not offered at this branch.'], 422);
        }

        $service = $variant->service;
        $kind = ServiceKind::of($service?->service_type);
        $minutes = (int) ($variant->duration_minutes ?: 30);
        $start = Carbon::parse("{$date} {$time}");
        $isToday = $date === now()->format('Y-m-d');
        $imminent = $isToday && $start->lte(now()->addMinutes(self::IMMINENT_MINUTES));
        $needsStaff = ServiceKind::needsTherapist($kind);

        return response()->json(['data' => [
            'service_name' => $service?->name,
            'duration_minutes' => $minutes,
            'needs_staff' => $needsStaff,
            'needs_facility' => \App\Models\Service::hasFacilityRequirement()
                && ServiceKind::needsFacility($kind, (bool) $service?->facility_required),
            'staff' => $needsStaff ? $this->staff($branchId, (int) $service?->id, $date, $time, $minutes, $isToday, $imminent) : [],
            'facilities' => $this->facilities($branchId, (int) $service?->id, $date, $time, $minutes),
        ]]);
    }

    /** @return array<int, array<string, mixed>> */
    private function staff(int $branchId, int $serviceId, string $date, string $time, int $minutes, bool $isToday, bool $imminent): array
    {
        $therapists = Staff::where('spa_branch_id', $branchId)
            ->where('role', 'therapist')
            ->where('status', 'active')
            ->orderBy('first_name')
            ->get();
        if ($therapists->isEmpty()) {
            return [];
        }

        $rows = $this->rotation->forBranch($branchId, $date, $therapists)->keyBy('staff_id');
        $conflicts = $this->availability->staffConflicts($therapists->pluck('id')->all(), $date, $time, $minutes);

        $result = $therapists->map(function (Staff $staff) use ($rows, $conflicts, $serviceId, $date, $time, $minutes, $isToday, $imminent) {
            $row = $rows->get($staff->id) ?? [];
            $onFloor = (bool) ($row['in_queue'] ?? false);
            $qualified = ! $serviceId || $this->availability->isStaffQualified($staff->id, $serviceId)['ok'];
            $shift = $this->availability->staffMatchesSchedule($staff->id, $date, $time, $minutes);

            // [status, reason, can_take]
            [$status, $reason, $canTake] = match (true) {
                ! $qualified => ['unavailable', "Doesn't do this service", false],
                isset($conflicts[$staff->id]) => ['busy', $conflicts[$staff->id], false],
                // Here today: the floor is what counts, whatever their shift says.
                $isToday && $onFloor && $imminent && ($row['rotation_status'] ?? 'free') === RotationTurn::IN_SERVICE => ['busy', 'With a client right now', false],
                $isToday && $onFloor => ['available', ($row['rotation_status'] ?? 'free') === RotationTurn::ASSIGNED ? 'Has a client waiting' : 'On the floor', true],
                ! $shift['ok'] => ['unavailable', $this->shortReason($shift['reason']), false],
                $isToday && $imminent => ['not_timed_in', 'Not timed in yet', false],
                $isToday => ['not_timed_in', 'Scheduled, not timed in yet', true],
                default => ['available', 'On shift', true],
            };

            return [
                'uuid' => $staff->uuid,
                'name' => trim("{$staff->first_name} {$staff->last_name}"),
                'specialties' => $staff->specialtyList(),
                'status' => $status,
                'reason' => $reason,
                'can_take' => $canTake,
                'queue_position' => $row['position'] ?? null,
                'in_queue' => $onFloor,
                'rotation_status' => $row['rotation_status'] ?? 'free',
                'is_next_up' => false,
            ];
        })->values();

        // Next up for THIS service: the first in the line who can take it and isn't mid-service.
        $line = $result->filter(fn (array $r) => $r['can_take'] && $r['in_queue'])->sortBy('queue_position');
        $next = $line->first(fn (array $r) => RotationTurn::canTakeNext($r['in_queue'], $r['rotation_status']));
        if ($next) {
            $result = $result->map(fn (array $r) => $r['uuid'] === $next['uuid'] ? array_merge($r, ['is_next_up' => true]) : $r);
        }

        // Who can take it first, in rotation order; the rest after.
        return $result->sortBy([
            fn (array $a, array $b) => $b['can_take'] <=> $a['can_take'],
            fn (array $a, array $b) => ($a['queue_position'] ?? PHP_INT_MAX) <=> ($b['queue_position'] ?? PHP_INT_MAX),
        ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function facilities(int $branchId, int $serviceId, string $date, string $time, int $minutes): array
    {
        $facilities = Facility::with('services:id,uuid')->where('spa_branch_id', $branchId)->orderBy('name')->get();
        $reasons = $this->availability->facilityReasons($facilities, $serviceId ?: null, $date, $time, $minutes);

        return $facilities->map(fn (Facility $f) => [
            'uuid' => $f->uuid,
            'name' => $f->name,
            'category' => $f->category,
            'available' => $reasons[$f->id] === null,
            'reason' => $reasons[$f->id],
        ])->values()->all();
    }

    // The schedule check's sentences are written for an error toast; the list wants them short.
    private function shortReason(?string $reason): string
    {
        return match (true) {
            str_contains((string) $reason, 'day off') => 'Day off',
            default => 'Not on shift then',
        };
    }
}

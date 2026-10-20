<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Support\BookingBoard;
use App\Support\BranchClosures;
use Carbon\Carbon;

// The front desk's New Appointment page: one day at their branch, with who
// can take a visit of a given length at each start time. Read-only — it loads
// the day (a handful of queries) and hands it to App\Support\BookingBoard,
// where the rules are.
//
// It reads the same things the booking itself is checked against (the
// branch's hours and closures; each therapist's shift and leave; the
// bookings already made), so what the desk is shown and what a
// booking will accept don't drift apart. A booking that asked for a therapist
// by name but has nobody assigned yet counts as theirs.
class BookingBoardService
{
    // The front desk is offered a start every half hour (the same spacing
    // the app offers its clients — BookingSlotService::STEP_MINUTES).
    public const STEP_MINUTES = 30;

    // Used when the owner hasn't entered hours for that weekday.
    private const FALLBACK_OPEN = '09:00';
    private const FALLBACK_CLOSE = '21:00';

    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private AppointmentAvailabilityService $availabilityService,
    ) {
    }

    public function board(User $user, string $date, int $duration)
    {
        // The branch the front desk is working in (the web sends it).
        $branchId = \App\Support\AccountScope::workingBranchId($this->spaBusinessRepository->branchIdsForUser($user));
        if (! $branchId) {
            return response()->json(['message' => 'No branch found for this account.'], 422);
        }

        $result = [
            'date' => $date,
            'duration_minutes' => $duration,
            'step_minutes' => self::STEP_MINUTES,
            'closed_reason' => null,
            'open' => null,
            'close' => null,
            'slots' => [],
            'therapists' => [],
        ];

        $therapists = Staff::where('spa_branch_id', $branchId)
            ->where('role', 'therapist')
            ->where('status', 'active')
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'uuid', 'first_name', 'last_name']);
        $names = $therapists->mapWithKeys(fn (Staff $s) => [$s->uuid => trim("{$s->first_name} {$s->last_name}")]);

        $day = $this->dayWindow($branchId, $date);
        if ($day['closed']) {
            $result['closed_reason'] = $day['reason'];

            return ['data' => $result];
        }

        $board = BookingBoard::build(
            $day,
            $this->therapistRows($therapists, $date),
            $this->busyWindows($branchId, $date),
            $duration,
            self::STEP_MINUTES,
            Carbon::parse($date)->isToday() ? now()->hour * 60 + now()->minute : null,
        );

        $result['open'] = BookingBoard::clock($day['open']);
        $result['close'] = BookingBoard::clock($day['close']);
        $result['slots'] = $board['slots'];
        $result['therapists'] = array_map(fn (array $row) => ['name' => $names[$row['uuid']] ?? ''] + $row, $board['therapists']);

        return ['data' => $result];
    }

    // The branch's hours for the date, in minutes from its midnight.
    private function dayWindow(int $branchId, string $date): array
    {
        $schedule = BranchSchedule::where('spa_branch_id', $branchId)
            ->where('day_of_week', Carbon::parse($date)->format('l'))
            ->first();

        if ($schedule?->is_closed) {
            return ['closed' => true, 'reason' => 'The branch is closed on this day.'];
        }

        $open = BookingBoard::minutes($schedule?->opening_time ?: self::FALLBACK_OPEN);
        $close = BookingBoard::minutes($schedule?->closing_time ?: self::FALLBACK_CLOSE);
        // Same conventions as AppointmentAvailabilityService::branchIsOpen:
        // equal times = open all day; closing <= opening = closes next day.
        if ($close <= $open) {
            $close += 1440;
        }

        // Temporary closures (a holiday, repairs) touching the day's hours.
        // One over the whole working day reads as a closed day, with its reason.
        $midnight = Carbon::parse($date)->startOfDay();
        $openAt = $midnight->copy()->addMinutes($open);
        $closeAt = $midnight->copy()->addMinutes($close);
        $closures = [];
        foreach (BranchClosures::overlapping($branchId, $openAt, $closeAt) as $closure) {
            if ($closure->starts_at->lte($openAt) && $closure->ends_at->gte($closeAt)) {
                return ['closed' => true, 'reason' => BranchClosures::refusal($closure)];
            }
            $closures[] = [
                (int) max($midnight->diffInMinutes($closure->starts_at, false), 0),
                (int) $midnight->diffInMinutes($closure->ends_at, false),
            ];
        }

        return ['closed' => false, 'open' => $open, 'close' => $close, 'closures' => $closures];
    }

    // Each therapist's shifts for the date, and whether they are on leave.
    private function therapistRows($therapists, string $date): array
    {
        $ids = $therapists->pluck('id')->all();

        $schedules = StaffSchedule::whereIn('staff_id', $ids)
            ->where('day_of_week', Carbon::parse($date)->format('l'))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->get()
            ->groupBy('staff_id');

        $onLeave = Attendance::whereIn('staff_id', $ids)
            ->whereDate('attendance_date', $date)
            ->where('status', 'On Leave')
            ->pluck('staff_id')
            ->all();

        return $therapists->map(function (Staff $staff) use ($schedules, $onLeave) {
            $rows = $schedules->get($staff->id) ?? collect();

            return [
                'id' => $staff->id,
                'uuid' => $staff->uuid,
                'on_leave' => in_array($staff->id, $onLeave, true),
                'has_schedule' => $rows->isNotEmpty(),
                'shifts' => $rows
                    ->filter(fn ($row) => ! $row->is_day_off && $row->start_time && $row->end_time)
                    ->map(fn ($row) => [
                        'start' => BookingBoard::minutes($row->start_time),
                        'end' => BookingBoard::minutes($row->end_time),
                    ])
                    ->values()->all(),
            ];
        })->values()->all();
    }

    // The day's open bookings as busy windows, each with who is on it: the
    // therapists assigned, or — while nobody is — the one it asked for.
    private function busyWindows(int $branchId, string $date): array
    {
        $remembers = Appointment::remembersRequests();

        return Appointment::where('spa_branch_id', $branchId)
            ->whereDate('appointment_date', $date)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW, Appointment::STATUS_COMPLETED])
            ->with(['services.serviceVariant', 'services.therapistAssignments'])
            ->get()
            ->map(function (Appointment $a) use ($remembers) {
                $start = BookingBoard::minutes((string) $a->appointment_time);
                $staff = $a->services
                    ->flatMap(fn ($s) => $s->therapistAssignments->whereIn('assignment_status', ['Assigned', 'In Progress'])->pluck('staff_id'))
                    ->unique()->values()->all();
                if (! $staff && $remembers && $a->requested_staff_id) {
                    $staff = [(int) $a->requested_staff_id];
                }

                return [
                    'start' => $start,
                    'end' => $start + max($this->availabilityService->estimatedDurationMinutes($a), 30),
                    'staff' => array_map('intval', $staff),
                ];
            })
            ->values()->all();
    }
}

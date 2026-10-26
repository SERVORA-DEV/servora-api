<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\BranchSchedule;
use App\Models\Notification;
use App\Models\SpaBranch;
use App\Repository\NotificationRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// What a client is told when something the spa does reaches a booking they have
// made: the branch closing on their day, its opening hours changing so their time
// no longer fits, or it opening again. Only clients with an account in the app
// have a feed (a walk-in typed at the desk has none), and a notice is never sent
// twice in a few minutes for the same booking, so a week of hours saved day by day
// is one message.
class ClientBookingNotices
{
    // The same notice, to the same booking, is not repeated inside this many minutes.
    private const QUIET_MINUTES = 15;

    public function __construct(
        private AppointmentAvailabilityService $availability,
        private NotificationRepository $notifications,
    ) {
    }

    /**
     * Bookings still to come that start inside, or run into, a window.
     *
     * @return Collection<int, Appointment>
     */
    public function bookingsInside(SpaBranch $branch, Carbon $start, Carbon $end): Collection
    {
        return Appointment::where('spa_branch_id', $branch->id)
            ->where('status', Appointment::STATUS_SCHEDULED)
            // A day early, for a visit that started the evening before.
            ->whereBetween('appointment_date', [$start->copy()->subDay()->toDateString(), $end->toDateString()])
            ->with(['client', 'services.serviceVariant'])
            ->get()
            ->filter(function (Appointment $a) use ($start, $end) {
                $from = $this->startsAt($a);
                $until = $from->copy()->addMinutes(max($this->availability->estimatedDurationMinutes($a), 30));

                return $from->lt($end) && $until->gt($start);
            })
            ->values();
    }

    /** The branch will be closed over a window their booking falls in. */
    public function closing(SpaBranch $branch, Carbon $start, Carbon $end, string $period, string $reason): int
    {
        return $this->tell(
            $this->bookingsInside($branch, $start, $end),
            'The spa will be closed at your booking time',
            fn (Appointment $a) => "{$branch->branch_name} will be closed {$period} ({$reason}), which includes your booking {$a->appointment_number} on {$this->when($a)}. The spa will contact you, or you can reschedule it in the app.",
        );
    }

    /** A closure was taken away or ended early: the bookings it had put in doubt are on. */
    public function reopened(SpaBranch $branch, Carbon $start, Carbon $end): int
    {
        return $this->tell(
            $this->bookingsInside($branch, $start, $end),
            'Your booking is going ahead',
            fn (Appointment $a) => "{$branch->branch_name} is open again, so your booking {$a->appointment_number} on {$this->when($a)} will go ahead as planned.",
        );
    }

    /** The opening hours changed: the bookings that no longer fit them are told. */
    public function hoursChanged(SpaBranch $branch): int
    {
        $week = BranchSchedule::where('spa_branch_id', $branch->id)->get()->keyBy('day_of_week');

        $outside = Appointment::where('spa_branch_id', $branch->id)
            ->where('status', Appointment::STATUS_SCHEDULED)
            ->whereDate('appointment_date', '>=', now()->toDateString())
            ->with(['client', 'services.serviceVariant'])
            ->get()
            ->filter(function (Appointment $a) use ($week) {
                $row = $week->get(Carbon::parse($a->appointment_date)->format('l'));
                if (! $row) {
                    return false; // no hours set for that day: nothing was changed against it
                }
                if ($row->is_closed) {
                    return true;
                }

                $date = Carbon::parse($a->appointment_date)->toDateString();
                $open = Carbon::parse("{$date} " . ($row->opening_time ?: '00:00'));
                $close = Carbon::parse("{$date} " . ($row->closing_time ?: '23:59'));
                // Equal times mean open all day; closing at or before opening means after midnight.
                if ($row->opening_time && $row->closing_time && $row->opening_time === $row->closing_time) {
                    return false;
                }
                if ($close->lte($open)) {
                    $close->addDay();
                }
                $start = $this->startsAt($a);
                $end = $start->copy()->addMinutes(max($this->availability->estimatedDurationMinutes($a), 30));

                return $start->lt($open) || $end->gt($close);
            })
            ->values();

        return $this->tell(
            $outside,
            'Opening hours have changed',
            fn (Appointment $a) => "{$branch->branch_name} has changed its opening hours, and your booking {$a->appointment_number} on {$this->when($a)} is now outside them. Please contact the spa, or reschedule it in the app.",
        );
    }

    /**
     * @param  Collection<int, Appointment>  $bookings
     * @param  callable(Appointment): string  $message
     */
    private function tell(Collection $bookings, string $title, callable $message): int
    {
        $told = 0;
        foreach ($bookings as $appointment) {
            $userId = $appointment->client?->user_id;
            if (! $userId || $this->alreadyTold($userId, $title, $appointment->uuid)) {
                continue;
            }
            try {
                $this->notifications->create($userId, $title, $message($appointment), 'Appointment', $appointment->uuid);
                $told++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $told;
    }

    private function alreadyTold(int $userId, string $title, string $reference): bool
    {
        return Notification::where('user_id', $userId)
            ->where('title', $title)
            ->where('reference_uuid', $reference)
            ->where('created_at', '>=', now()->subMinutes(self::QUIET_MINUTES))
            ->exists();
    }

    private function startsAt(Appointment $a): Carbon
    {
        return Carbon::parse(Carbon::parse($a->appointment_date)->toDateString() . ' ' . substr((string) $a->appointment_time, 0, 5));
    }

    private function when(Appointment $a): string
    {
        return $this->startsAt($a)->format('D, M j \a\t g:i A');
    }
}

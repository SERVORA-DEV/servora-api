<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Notification;
use App\Models\SpaBusinessSetting;
use App\Models\Staff;
use App\Service\Business\AttendanceStatusCalculator;
use App\Service\Business\StaffNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;

// The front desk's running to-do list, delivered to the bell of each
// branch's front officers and manager (not the owner — this is floor
// operations, and it would bury the owner's feed):
//   - "Upcoming booking": a scheduled booking starts within the owner's
//     staff reminder lead time (Settings > Notifications).
//   - "Not checked in yet": someone with a shift today is past their start
//     time plus the late threshold (Settings > Staff Policies) and hasn't
//     checked in or been marked on leave.
//   - "Still checked in": someone is still clocked in 30+ minutes after
//     their shift ended.
// Each notice goes out once: it's skipped when one with the same title and
// reference already exists (the booking's uuid, or the staff member's uuid
// today).
class SendFrontDeskAlerts extends Command
{
    protected $signature = 'staff:send-front-desk-alerts';

    protected $description = 'Notify front desk and managers about upcoming bookings and attendance issues.';

    private const STILL_IN_AFTER_MINUTES = 30;

    public function handle(StaffNotifier $notifier, AttendanceStatusCalculator $calculator): int
    {
        $sent = $this->upcomingBookings($notifier) + $this->attendance($notifier, $calculator);
        $this->info("Sent {$sent} front-desk alert(s).");

        return self::SUCCESS;
    }

    private function upcomingBookings(StaffNotifier $notifier): int
    {
        $sent = 0;

        Appointment::query()
            ->where('status', Appointment::STATUS_SCHEDULED)
            ->where('appointment_date', now()->toDateString())
            ->where('appointment_time', '>=', now()->format('H:i:s'))
            ->with('client', 'branch.business.settings')
            ->chunkById(200, function ($appointments) use ($notifier, &$sent) {
                foreach ($appointments as $appointment) {
                    $settings = $appointment->branch?->business?->settings?->section('notifications')
                        ?? SpaBusinessSetting::DEFAULTS['notifications'];
                    if (! $appointment->branch || ! $settings['send_staff_reminder'] || ! $settings['in_app_channel']) {
                        continue;
                    }

                    $startsAt = Carbon::parse($appointment->appointment_date->toDateString() . ' ' . $appointment->appointment_time);
                    if ($startsAt->gt(now()->addHours((int) $settings['staff_reminder_timing']))) {
                        continue;
                    }
                    if ($this->alreadySent('Upcoming booking', $appointment->uuid, now()->startOfDay())) {
                        continue;
                    }

                    $client = trim(($appointment->client?->first_name ?? '') . ' ' . ($appointment->client?->last_name ?? '')) ?: 'A client';
                    $notifier->branch(
                        $appointment->branch,
                        'Upcoming booking',
                        "{$client} is booked " . $startsAt->diffForHumans(['parts' => 1]) . ' (' . $startsAt->format('g:i A') . "). Booking no. {$appointment->appointment_number}.",
                        type: 'Appointment',
                        includeOwner: false,
                        reference: $appointment->uuid,
                    );
                    $sent++;
                }
            });

        return $sent;
    }

    private function attendance(StaffNotifier $notifier, AttendanceStatusCalculator $calculator): int
    {
        $sent = 0;
        $date = now()->toDateString();

        Staff::query()
            ->where('status', 'active')
            ->with([
                'branch.business.settings',
                'schedules',
                'attendance' => fn ($q) => $q->where('attendance_date', $date),
            ])
            ->chunkById(200, function ($staffMembers) use ($notifier, $calculator, $date, &$sent) {
                foreach ($staffMembers as $staff) {
                    $branch = $staff->branch;
                    $business = $branch?->business;
                    if (! $branch || ! $business) {
                        continue;
                    }
                    $policy = $business->settings?->section('staff_policy') ?? SpaBusinessSetting::DEFAULTS['staff_policy'];
                    if (! $policy['attendance_tracking']) {
                        continue;
                    }

                    $resolved = $calculator->resolve(
                        $staff,
                        $staff->attendance->first(),
                        $date,
                        $staff->schedules,
                        graceMinutes: AttendanceStatusCalculator::graceMinutesFor($business),
                    );
                    $attendance = $staff->attendance->first();
                    $name = trim("{$staff->first_name} {$staff->last_name}");

                    $alert = null;
                    if ($resolved['scheduled_start'] && ! $attendance?->check_in_at && ! $attendance?->status) {
                        $start = Carbon::parse("{$date} {$resolved['scheduled_start']}");
                        if (now()->gt($start->copy()->addMinutes(AttendanceStatusCalculator::graceMinutesFor($business)))) {
                            $alert = ['Not checked in yet', "{$name}'s shift started at " . $start->format('g:i A') . " and they haven't checked in."];
                        }
                    } elseif ($attendance?->check_in_at && ! $attendance->check_out_at && $resolved['scheduled_end']) {
                        $end = Carbon::parse("{$date} {$resolved['scheduled_end']}");
                        if ($attendance->check_in_at->lt($end) && now()->gt($end->copy()->addMinutes(self::STILL_IN_AFTER_MINUTES))) {
                            $alert = ['Still checked in', "{$name}'s shift ended at " . $end->format('g:i A') . ' — remember to check them out.'];
                        }
                    }

                    if (! $alert || $this->alreadySent($alert[0], $staff->uuid, now()->startOfDay())) {
                        continue;
                    }

                    $notifier->branch($branch, $alert[0], $alert[1], type: 'Attendance', includeOwner: false, reference: $staff->uuid);
                    $sent++;
                }
            });

        return $sent;
    }

    private function alreadySent(string $title, string $reference, Carbon $since): bool
    {
        return Notification::where('title', $title)
            ->where('reference_uuid', $reference)
            ->where('created_at', '>=', $since)
            ->exists();
    }
}

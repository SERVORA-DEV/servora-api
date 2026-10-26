<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Billing;
use App\Models\SpaBranch;
use App\Service\Business\ClientNotifier;
use Illuminate\Http\JsonResponse;

// What it means for a branch to be suspended by Servora — its own suspension, or
// its whole business's. A suspension WINDS DOWN the branch: nothing new starts
// (no new booking, no check-in), but a visit already open can still be finished
// and paid, so nobody is left mid-service or with a bill that cannot be paid. The
// clients booked for later are told it is on hold.
class Suspension
{
    public static function isSuspended(SpaBranch $branch): bool
    {
        $branch->loadMissing('business');

        return $branch->verification_status === 'Suspended' || $branch->business?->verification_status === 'Suspended';
    }

    /** The refusal for something new at a suspended branch, or null when it is not suspended. */
    public static function refusal(?SpaBranch $branch): ?JsonResponse
    {
        if (! $branch || ! self::isSuspended($branch)) {
            return null;
        }

        return response()->json([
            'message' => "{$branch->branch_name} is suspended by Servora, so new bookings and check-ins are paused. Visits already open can still be finished and paid.",
        ], 422);
    }

    /**
     * What a suspension would reach, for the administrator to see first.
     *
     * @param  int[]  $branchIds
     * @return array{open_visits: int, unpaid_bills: int, upcoming_bookings: int}
     */
    public static function impact(array $branchIds): array
    {
        if (! $branchIds) {
            return ['open_visits' => 0, 'unpaid_bills' => 0, 'upcoming_bookings' => 0];
        }

        return [
            // Under way: the client is here.
            'open_visits' => Appointment::whereIn('spa_branch_id', $branchIds)
                ->whereIn('status', [Appointment::STATUS_CHECKED_IN, Appointment::STATUS_IN_SERVICE])->count(),
            'unpaid_bills' => Billing::whereIn('spa_branch_id', $branchIds)
                ->whereNotIn('status', ['Paid', 'Cancelled', 'Refunded'])->count(),
            // Booked for later: the clients who are not here yet.
            'upcoming_bookings' => Appointment::whereIn('spa_branch_id', $branchIds)
                ->where('status', Appointment::STATUS_SCHEDULED)
                ->whereDate('appointment_date', '>=', now()->toDateString())->count(),
        ];
    }

    /**
     * Tell the clients booked for later that the spa is back, so their booking is on.
     *
     * @param  int[]  $branchIds
     */
    public static function tellClientsBack(array $branchIds): int
    {
        if (! $branchIds) {
            return 0;
        }

        $notifier = app(ClientNotifier::class);
        $told = 0;
        Appointment::whereIn('spa_branch_id', $branchIds)
            ->where('status', Appointment::STATUS_SCHEDULED)
            ->whereDate('appointment_date', '>=', now()->toDateString())
            ->with(['client', 'branch.business'])
            // A branch that is still suspended, by itself or through its business, is not back.
            ->chunkById(200, function ($appointments) use ($notifier, &$told) {
                foreach ($appointments as $appointment) {
                    if (! $appointment->branch || self::isSuspended($appointment->branch)) {
                        continue;
                    }
                    $notifier->appointment(
                        $appointment,
                        'Your booking is going ahead',
                        "{$appointment->branch->branch_name} is available again, so your booking {$appointment->appointment_number} will go ahead as planned.",
                    );
                    $told++;
                }
            });

        return $told;
    }

    /**
     * Tell the clients booked for later that their booking is on hold.
     *
     * @param  int[]  $branchIds
     */
    public static function tellClients(array $branchIds): int
    {
        if (! $branchIds) {
            return 0;
        }

        $notifier = app(ClientNotifier::class);
        $told = 0;
        Appointment::whereIn('spa_branch_id', $branchIds)
            ->where('status', Appointment::STATUS_SCHEDULED)
            ->whereDate('appointment_date', '>=', now()->toDateString())
            ->with(['client', 'branch'])
            ->chunkById(200, function ($appointments) use ($notifier, &$told) {
                foreach ($appointments as $appointment) {
                    $notifier->appointment(
                        $appointment,
                        'Your booking is on hold',
                        ($appointment->branch?->branch_name ?? 'The spa') . " is temporarily unavailable, so your booking {$appointment->appointment_number} is on hold. The spa will contact you about it.",
                    );
                    $told++;
                }
            });

        return $told;
    }
}

<?php

namespace App\Service\Client;

use App\Http\Resources\Client\ClientAppointmentResource;
use App\Http\Resources\Client\ClientReviewResource;
use App\Models\Appointment;
use App\Models\Queue;
use App\Models\Review;
use App\Models\Staff;
use App\Models\User;
use App\Service\Business\AppointmentAvailabilityService;
use App\Service\Business\AppointmentService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

// A mobile client's own bookings, across every spa they've booked with.
//
// "Mine" is appointments.client_id → clients.user_id = the signed-in user —
// the link ClientService::findOrCreateForUser makes at booking time. Every
// lookup is scoped through that, and anything else 404s (not 403s), the
// same anti-enumeration posture as the public branch lookups.
//
// Cancel and reschedule reuse AppointmentService::performCancel/
// performReschedule, so a client can never leave a booking in a state the
// front desk couldn't have; the client-only rules (own booking, still
// Scheduled, not already started, therapist still free) are checked here
// first.
class ClientBookingService
{
    private const RELATIONS = [
        'branch.business',
        'branch.coverPhoto',
        'services.serviceVariant.service',
        'services.therapistAssignments.staff',
        'services.sourceAppointmentPackage.package',
        'packages.package',
        'queue',
        'billing.payments',
        'review',
    ];

    public function __construct(
        private AppointmentService $appointmentService,
        private AppointmentAvailabilityService $availabilityService,
    ) {
    }

    private function mine(User $user): Builder
    {
        return Appointment::query()->whereHas('client', fn ($q) => $q->where('user_id', $user->id));
    }

    private function findMine(User $user, string $uuid): Appointment
    {
        return $this->mine($user)->where('uuid', $uuid)->with(self::RELATIONS)->firstOrFail();
    }

    public function list(User $user, ?string $scope, int $perPage = 20)
    {
        $query = $this->mine($user)->with(self::RELATIONS);

        switch ($scope) {
            case 'upcoming':
                $query->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CHECKED_IN, Appointment::STATUS_IN_SERVICE])
                    ->orderBy('appointment_date')->orderBy('appointment_time');
                break;
            case 'past':
                $query->whereIn('status', [Appointment::STATUS_COMPLETED, Appointment::STATUS_NO_SHOW])
                    ->orderByDesc('appointment_date')->orderByDesc('appointment_time');
                break;
            case 'cancelled':
                $query->where('status', Appointment::STATUS_CANCELLED)
                    ->orderByDesc('appointment_date')->orderByDesc('appointment_time');
                break;
            default:
                $query->orderByDesc('appointment_date')->orderByDesc('appointment_time');
        }

        return ClientAppointmentResource::collection($query->paginate(min(max($perPage, 1), 50)));
    }

    public function show(User $user, string $uuid)
    {
        return new ClientAppointmentResource($this->findMine($user, $uuid));
    }

    public function cancel(User $user, string $uuid, ?string $reason)
    {
        return DB::transaction(function () use ($user, $uuid, $reason) {
            $appointment = $this->mine($user)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            if (! ClientAppointmentResource::clientCanChange($appointment)) {
                return response()->json(['message' => 'This booking can no longer be cancelled from the app. Please contact the spa.'], 422);
            }

            $error = $this->appointmentService->performCancel($appointment, $reason ?: 'Cancelled by client');
            if ($error) {
                return $error;
            }

            return new ClientAppointmentResource($this->findMine($user, $uuid));
        });
    }

    public function reschedule(User $user, string $uuid, array $payload)
    {
        return DB::transaction(function () use ($user, $uuid, $payload) {
            $appointment = $this->mine($user)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $appointment->load('services.serviceVariant', 'services.therapistAssignments');

            if (! ClientAppointmentResource::clientCanChange($appointment)) {
                return response()->json(['message' => 'This booking can no longer be rescheduled from the app. Please contact the spa.'], 422);
            }

            $startsAt = Carbon::parse("{$payload['appointment_date']} {$payload['appointment_time']}");
            if ($startsAt->lt(now())) {
                return response()->json(['message' => 'Please choose a future time.'], 422);
            }

            // Same hard gate as booking: a therapist the client asked for
            // must actually be working and free at the new time, or the
            // move is refused rather than silently leaving them unassigned.
            $duration = max($this->availabilityService->estimatedDurationMinutes($appointment), 30);
            $staffIds = $appointment->services
                ->flatMap(fn ($s) => $s->therapistAssignments->where('assignment_status', 'Assigned')->pluck('staff_id'))
                ->unique();

            foreach ($staffIds as $staffId) {
                $staff = Staff::find($staffId);
                $name = $staff ? trim("{$staff->first_name} {$staff->last_name}") : 'Your therapist';

                $schedule = $this->availabilityService->staffMatchesSchedule($staffId, $payload['appointment_date'], $payload['appointment_time'], $duration);
                if (! $schedule['ok']) {
                    return response()->json(['message' => "{$name} isn't available then. {$schedule['reason']}"], 422);
                }

                $booked = $this->availabilityService->isStaffAvailable($staffId, $payload['appointment_date'], $payload['appointment_time'], $duration, $appointment->id);
                if (! $booked['ok']) {
                    return response()->json(['message' => "{$name} isn't available then. {$booked['reason']}"], 422);
                }
            }

            $error = $this->appointmentService->performReschedule($appointment, [
                'appointment_date' => $payload['appointment_date'],
                'appointment_time' => $payload['appointment_time'],
            ]);
            if ($error) {
                return $error;
            }

            return new ClientAppointmentResource($this->findMine($user, $uuid));
        });
    }

    // Live position for the queue screen, polled by the app. Order is FIFO
    // by created_at, the same order the front desk's queue board uses
    // (QueueRepository::listForBranchDate).
    public function queue(User $user, string $uuid)
    {
        $appointment = $this->findMine($user, $uuid);
        $ticket = $appointment->queue;

        if (! $ticket) {
            return response()->json(['data' => [
                'appointment_uuid' => $appointment->uuid,
                'in_queue' => false,
                'status' => $appointment->status,
                'message' => $appointment->status === Appointment::STATUS_SCHEDULED
                    ? 'You join the queue when you check in at the front desk.'
                    : null,
            ]]);
        }

        $date = $ticket->appointment_date->format('Y-m-d');
        $sameDay = Queue::where('spa_branch_id', $ticket->spa_branch_id)->where('appointment_date', $date);

        $ahead = (clone $sameDay)
            ->where('queue_status', 'Waiting')
            ->where('created_at', '<', $ticket->created_at)
            ->with('appointment.services.serviceVariant')
            ->get();

        $nowServing = (clone $sameDay)
            ->whereIn('queue_status', ['Called', 'Serving'])
            ->orderByDesc('called_at')
            ->value('queue_number');

        $therapists = max(Staff::where('spa_branch_id', $ticket->spa_branch_id)->where('role', 'therapist')->where('status', 'active')->count(), 1);
        $minutesAhead = $ahead->sum(fn (Queue $q) => $q->appointment
            ? max($this->availabilityService->estimatedDurationMinutes($q->appointment), 30)
            : 30);

        $waiting = $ticket->queue_status === 'Waiting';

        return response()->json(['data' => [
            'appointment_uuid' => $appointment->uuid,
            'in_queue' => true,
            'status' => $appointment->status,
            'queue_number' => $ticket->queue_number,
            'queue_status' => $ticket->queue_status,
            'now_serving' => $nowServing,
            'ahead' => $waiting ? $ahead->count() : 0,
            'estimated_wait_minutes' => $waiting ? (int) ceil($minutesAhead / $therapists) : 0,
            'called_at' => optional($ticket->called_at)->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]]);
    }

    public function review(User $user, string $uuid, array $payload)
    {
        $appointment = $this->findMine($user, $uuid);

        if ($appointment->status !== Appointment::STATUS_COMPLETED) {
            return response()->json(['message' => 'You can review a visit once it is completed.'], 422);
        }

        if ($appointment->review) {
            return response()->json(['message' => 'You already reviewed this visit.'], 409);
        }

        $review = Review::create([
            'appointment_id' => $appointment->id,
            'client_id' => $appointment->client_id,
            'rating' => $payload['rating'],
            'comment' => $payload['comment'] ?? null,
            'is_anonymous' => (bool) ($payload['is_anonymous'] ?? false),
            'status' => 'Published',
            'reviewed_at' => now(),
        ]);

        return (new ClientReviewResource($review->load('appointment.branch.business', 'appointment.services.serviceVariant.service')))
            ->response()
            ->setStatusCode(201);
    }

    public function myReviews(User $user)
    {
        $reviews = Review::query()
            ->whereHas('appointment', fn ($q) => $q->whereHas('client', fn ($c) => $c->where('user_id', $user->id)))
            ->with('appointment.branch.business', 'appointment.services.serviceVariant.service')
            ->latest()
            ->paginate(50);

        return ClientReviewResource::collection($reviews);
    }
}

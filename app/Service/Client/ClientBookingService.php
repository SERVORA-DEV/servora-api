<?php

namespace App\Service\Client;

use App\Enums\AppointmentStatus;
use App\Enums\AssignmentStatus;
use App\Enums\QueueStatus;
use App\Enums\ReviewStatus;
use App\Enums\StaffStatus;
use App\Exceptions\ChangedElsewhere;
use App\Http\Resources\Client\ClientAppointmentResource;
use App\Http\Resources\Client\ClientReviewResource;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Client;
use App\Models\Queue;
use App\Models\Review;
use App\Models\SpaBranch;
use App\Models\Staff;
use App\Models\User;
use App\Repository\Business\QueueRepository;
use App\Service\Business\AppointmentAvailabilityService;
use App\Service\Business\AppointmentBookingService;
use App\Service\Business\MarketplaceReadiness;
use App\Service\Business\StaffNotifier;
use App\Support\Branches\BusinessDay;
use App\Support\Platform\Suspension;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// A mobile client's own bookings, across every spa they've booked with.
//
// "Mine" is appointments.client_id → clients.user_id = the signed-in user —
// the link ClientService::findOrCreateForUser makes at booking time. Every
// lookup is scoped through that, and anything else 404s (not 403s), the
// same anti-enumeration posture as the public branch lookups.
//
// Cancel and reschedule reuse AppointmentBookingService::performCancel/
// performReschedule, so a client can never leave a booking in a state the
// front desk couldn't have; the client-only rules (own booking, still
// Scheduled, not already started, therapist still free) are checked here
// first.
class ClientBookingService
{
    // Public: AppointmentBookingService::createClientAppointment returns the new
    // booking in this same shape.
    public const RELATIONS = [
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
        private AppointmentBookingService $booking,
        private AppointmentAvailabilityService $availabilityService,
        private StaffNotifier $staffNotifier,
        private BookingSlotService $slotService,
        private QueueRepository $queueRepository,
    ) {}

    private function clientName(Appointment $appointment): string
    {
        return trim("{$appointment->client?->first_name} {$appointment->client?->last_name}") ?: 'A client';
    }

    private function mine(User $user): Builder
    {
        return Appointment::query()->whereHas('client', fn ($q) => $q->where('user_id', $user->id));
    }

    // What ClientAppointmentResource shows: the relations, and whether the
    // client has already rated this booking's spa (`spa_rated`).
    private function shown(User $user): Builder
    {
        return $this->mine($user)->with(self::RELATIONS)->addSelect([
            'spa_rated' => Review::ratedBranchColumn(),
            // Their review of this spa (from any visit there), to update it.
            'spa_review_uuid' => Review::branchReviewColumn('uuid'),
            'spa_review_rating' => Review::branchReviewColumn('rating'),
            'spa_review_at' => Review::branchReviewColumn('reviewed_at'),
        ]);
    }

    private function findMine(User $user, string $uuid): Appointment
    {
        return $this->shown($user)->where('appointments.uuid', $uuid)->firstOrFail();
    }

    public function list(User $user, ?string $scope, int $perPage = 20)
    {
        $query = $this->shown($user);

        switch ($scope) {
            case 'upcoming':
                $query->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::CheckedIn->value, AppointmentStatus::InService->value])
                    ->orderBy('appointment_date')->orderBy('appointment_time');
                break;
            case 'past':
                $query->whereIn('status', [AppointmentStatus::Completed->value, AppointmentStatus::NoShow->value])
                    ->orderByDesc('appointment_date')->orderByDesc('appointment_time');
                break;
            case 'cancelled':
                $query->where('status', AppointmentStatus::Cancelled->value)
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

            if ($problem = ClientAppointmentResource::changeProblem($appointment, 'cancel')) {
                return response()->json(['message' => $problem], 422);
            }

            $error = $this->booking->performCancel($appointment, $reason ?: 'Cancelled by client');
            if ($error) {
                return $error;
            }

            $this->staffNotifier->appointment(
                $appointment,
                'Booking cancelled by client',
                "{$this->clientName($appointment)} cancelled booking {$appointment->appointment_number}."
                    .($reason ? " Reason: {$reason}" : ''),
                'on_cancellation',
            );

            return new ClientAppointmentResource($this->findMine($user, $uuid));
        });
    }

    // What the app needs before offering "Book now" at a spa: whether the
    // client's booking in the app is paused for missed bookings, how many they
    // have missed since their last visit, and the days that already hold as
    // many bookings of theirs as the spa allows in one day.
    public function bookingStatus(User $user, string $branchUuid)
    {
        $branch = SpaBranch::with('business')->where('uuid', $branchUuid)->firstOrFail();
        $policy = BookingPolicy::for($branch);
        $client = Client::where('spa_business_id', $branch->spa_business_id)->where('user_id', $user->id)->first();
        $missed = $client ? $this->booking->noShowCount($client->id) : 0;

        $dayLimit = $policy['max_bookings_per_client_per_day'];
        $busy = $client
            ? Appointment::where('client_id', $client->id)
                ->whereDate('appointment_date', '>=', BookingPolicy::now()->toDateString())
                ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value])
                ->pluck('appointment_date')->map(fn ($d) => Carbon::parse($d)->toDateString())
                ->countBy()->filter(fn ($n) => $n >= $dayLimit)->keys()->values()
            : collect();

        // Their upcoming bookings at any spa (day, start, length, branch), so the
        // app can keep them from picking a time that runs over one; the server
        // refuses it too (AppointmentBookingService::clientClash).
        $upcoming = Appointment::with(['branch', 'services.serviceVariant'])
            ->whereHas('client', fn ($q) => $q->where('user_id', $user->id))
            ->whereDate('appointment_date', '>=', BookingPolicy::now()->toDateString())
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value, AppointmentStatus::Completed->value])
            ->get()
            ->map(fn ($a) => [
                'date' => Carbon::parse($a->appointment_date)->toDateString(),
                'time' => substr((string) $a->appointment_time, 0, 5),
                'minutes' => max(1, $this->availabilityService->estimatedDurationMinutes($a)),
                'branch' => $a->branch?->branch_name,
            ])->values();

        return ['data' => BookingPolicy::missedBookingsNotice($policy, $missed, (string) $branch->business?->business_name) + ['booked_dates' => $busy, 'bookings' => $upcoming]];
    }

    public function reschedule(User $user, string $uuid, array $payload)
    {
        return DB::transaction(function () use ($user, $uuid, $payload) {
            $appointment = $this->mine($user)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $appointment->load('services.serviceVariant', 'services.therapistAssignments');

            if ($problem = ClientAppointmentResource::changeProblem($appointment, 'reschedule')) {
                return response()->json(['message' => $problem], 422);
            }

            // The new time has to follow the same rules as a new booking.
            $startsAt = Carbon::parse("{$payload['appointment_date']} {$payload['appointment_time']}");
            $branch = $appointment->branch;
            if ($problem = BookingPolicy::startTimeProblem(BookingPolicy::for($branch), $startsAt)) {
                return response()->json(['message' => $problem], 422);
            }
            if ($suspended = Suspension::refusal($branch)) {
                return $suspended;
            }
            if (! MarketplaceReadiness::acceptsChanges($branch)) {
                return response()->json(['message' => MarketplaceReadiness::NOT_CHANGEABLE], 422);
            }
            // Not onto a day that already holds the most bookings one client may have.
            $dayLimit = BookingPolicy::for($branch)['max_bookings_per_client_per_day'];
            if ($this->booking->clientBookingsOn($user, $branch->spa_business_id, $payload['appointment_date'], $appointment->id) >= $dayLimit) {
                return response()->json(['message' => AppointmentBookingService::dayLimitMessage($dayLimit, $payload['appointment_date'])], 422);
            }

            // Same hard gate as booking: a therapist the client asked for
            // must actually be working and free at the new time, or the
            // move is refused rather than silently leaving them unassigned.
            $duration = max($this->availabilityService->estimatedDurationMinutes($appointment), 30);

            // Not onto the time of another booking of theirs (at any spa).
            if ($clash = $this->booking->clientClash($user, $payload['appointment_date'], $payload['appointment_time'], $duration, $appointment->id)) {
                return response()->json(['message' => $this->booking->clientClashMessage($clash), 'code' => 'overlaps_existing_booking'], 422);
            }

            $staffIds = $appointment->services
                ->flatMap(fn ($s) => $s->therapistAssignments->where('assignment_status', AssignmentStatus::Assigned->value)->pluck('staff_id'))
                ->unique();

            foreach ($staffIds as $staffId) {
                $staff = Staff::find($staffId);
                $name = $staff ? trim("{$staff->first_name} {$staff->last_name}") : 'Your therapist';

                $schedule = $this->availabilityService->staffMatchesSchedule($staffId, $payload['appointment_date'], $payload['appointment_time'], $duration);
                if (! $schedule['ok']) {
                    return response()->json(['message' => "{$name} isn't available then. {$schedule['reason']}"], 422);
                }

                // A home visit keeps them for the trip there and back too.
                [$from, $span] = \App\Service\Business\HomeServiceRules::widen($appointment->isHomeService(), (int) $appointment->spa_branch_id, $payload['appointment_date'], $payload['appointment_time'], $duration);
                $booked = $this->availabilityService->isStaffAvailable($staffId, $payload['appointment_date'], $from, $span, $appointment->id);
                if (! $booked['ok']) {
                    return response()->json(['message' => "{$name} isn't available then. {$booked['reason']}"], 422);
                }
            }

            // Hours, end-before-close and "is a therapist left" —
            // the same check the reschedule screen's time chips came from.
            $slotProblem = $this->slotService->bookingProblem(
                $branch,
                $payload['appointment_date'],
                substr($payload['appointment_time'], 0, 5),
                $duration,
                $staffIds->isNotEmpty() ? Staff::find($staffIds->first()) : null,
                $appointment->id,
                $appointment->isHomeService(),
                \App\Support\Catalog\ServiceParts::needOf($appointment->loadMissing('services.serviceVariant')),
                \App\Support\Catalog\ServiceParts::roomServicesOf($appointment),
            );
            if ($slotProblem) {
                return response()->json(['message' => $slotProblem], 422);
            }

            $error = $this->booking->performReschedule($appointment, [
                'appointment_date' => $payload['appointment_date'],
                'appointment_time' => $payload['appointment_time'],
            ]);
            if ($error) {
                return $error;
            }

            // The client's own move, counted against the spa's limit.
            if (Appointment::countsReschedules()) {
                $appointment->increment('client_reschedule_count');
            }

            $this->staffNotifier->appointment(
                $appointment,
                'Booking rescheduled by client',
                "{$this->clientName($appointment)} moved booking {$appointment->appointment_number} to "
                    .$startsAt->format('D, M j \\a\\t g:i A').'.',
                'on_reschedule',
            );

            return new ClientAppointmentResource($this->findMine($user, $uuid));
        });
    }

    // Live position for the queue screen, polled by the app. "Ahead" is the
    // same line the Front Officer's Live Queue shows (QueueRepository::aheadOf):
    // by place in the line, walk-ins first when the branch prioritizes them.
    public function queue(User $user, string $uuid)
    {
        $appointment = $this->findMine($user, $uuid);
        $ticket = $appointment->queue;

        if (! $ticket) {
            // Called by the spa (CheckInService::callQueue) and not checked in yet.
            $called = $appointment->status === AppointmentStatus::Scheduled->value
                ? Cache::get(Queue::calledKey($appointment->id))
                : null;

            return response()->json(['data' => [
                'appointment_uuid' => $appointment->uuid,
                'in_queue' => false,
                'status' => $appointment->status,
                'called' => (bool) $called,
                'called_at' => $called,
                'message' => $appointment->status === AppointmentStatus::Scheduled->value
                    ? ($called
                        ? 'The spa is ready for you. Please come in and check in with the Front Officer.'
                        : 'You join the queue when you check in with the Front Officer.')
                    : null,
            ]]);
        }

        $date = $ticket->appointment_date->format('Y-m-d');
        $sameDay = Queue::where('spa_branch_id', $ticket->spa_branch_id)->where('appointment_date', $date);

        $ticket->setRelation('appointment', $appointment);
        $walkInsFirst = $appointment->branch
            ? (bool) (BookingPolicy::for($appointment->branch)['walk_ins_first_priority'] ?? false)
            : false;
        $ahead = $this->queueRepository->aheadOf($ticket, $walkInsFirst);

        $nowServing = (clone $sameDay)
            ->whereIn('queue_status', [QueueStatus::Called->value, QueueStatus::Serving->value])
            ->orderByDesc('called_at')
            ->value('queue_number');

        // Therapists actually in today (checked in, not out) share the line;
        // before anyone has checked in, fall back to the active roster.
        $roster = Staff::where('spa_branch_id', $ticket->spa_branch_id)->where('role', 'therapist')->where('status', StaffStatus::Active->value);
        $inToday = Attendance::whereIn('staff_id', (clone $roster)->select('id'))
            ->whereDate('attendance_date', BusinessDay::attendanceDate($ticket->spa_branch_id, $date))
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->count();
        $therapists = max($inToday ?: $roster->count(), 1);
        $minutesAhead = $ahead->sum(fn (Queue $q) => $q->appointment
            ? max($this->availabilityService->estimatedDurationMinutes($q->appointment), 30)
            : 30);

        $waiting = in_array($ticket->queue_status, [QueueStatus::Waiting->value, QueueStatus::Called->value], true);

        return response()->json(['data' => [
            'appointment_uuid' => $appointment->uuid,
            'in_queue' => true,
            'status' => $appointment->status,
            'queue_number' => $ticket->queue_number,
            'queue_status' => $ticket->queue_status,
            'now_serving' => $nowServing,
            'ahead' => $waiting ? $ahead->count() : 0,
            // Their place in line (1 = next), the same number the Front Officer's Live Queue shows.
            'position' => $waiting ? $ahead->count() + 1 : null,
            // A Front Officer moved them down the line in the last 30 minutes.
            'moved_down' => $ticket->movedDown(),
            'estimated_wait_minutes' => $waiting ? (int) ceil($minutesAhead / $therapists) : 0,
            'called_at' => optional($ticket->called_at)->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]]);
    }

    public function review(User $user, string $uuid, array $payload)
    {
        $appointment = $this->findMine($user, $uuid);

        if ($appointment->status !== AppointmentStatus::Completed->value) {
            return response()->json(['message' => 'You can review a visit once it is completed.'], 422);
        }

        if ($appointment->review) {
            return response()->json(['message' => 'You already reviewed this visit.'], 409);
        }

        // A spa is rated once (Review::ratedBranch). The client's row is locked
        // so two quick taps can't both get through.
        $review = DB::transaction(function () use ($appointment, $payload) {
            throw_unless(Client::whereKey($appointment->client_id)->lockForUpdate()->first(), ChangedElsewhere::class,
                'Your client profile changed. Refresh the appointment and try again.');
            if (Review::ratedBranch($appointment->client_id, $appointment->spa_branch_id)) {
                return null;
            }

            $review = Review::create([
                'appointment_id' => $appointment->id,
                'client_id' => $appointment->client_id,
                'rating' => $payload['rating'],
                'comment' => $payload['comment'] ?? null,
                'is_anonymous' => (bool) ($payload['is_anonymous'] ?? false),
                'status' => ReviewStatus::Published->value,
                'reviewed_at' => now(),
            ]);

            $this->staffNotifier->appointment(
                $appointment,
                'New review',
                "{$this->clientName($appointment)} left a {$payload['rating']}-star review for booking {$appointment->appointment_number}.",
                null,
                'Appointment',
                false,
            );

            return $review;
        });
        if (! $review) {
            $spa = $appointment->branch?->branch_name ?? 'this spa';

            return response()->json(['message' => "You've already rated {$spa}. Each spa can be rated once."], 409);
        }

        return (new ClientReviewResource($review->load('appointment.branch.business', 'appointment.services.serviceVariant.service')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Changes the client's rating of a spa: the stars, the comment and whether
     * their name shows. It stays their one review of that branch (it is not a
     * second one), and its date becomes today, as it is what they think now.
     */
    public function updateReview(User $user, string $uuid, array $payload)
    {
        $review = Review::query()
            ->where('uuid', $uuid)
            ->whereHas('appointment', fn ($q) => $q->whereHas('client', fn ($c) => $c->where('user_id', $user->id)))
            ->with('appointment')
            ->firstOrFail();

        $before = (int) $review->rating;
        $review->update([
            'rating' => $payload['rating'],
            'comment' => $payload['comment'] ?? null,
            'is_anonymous' => array_key_exists('is_anonymous', $payload) ? (bool) $payload['is_anonymous'] : $review->is_anonymous,
            'reviewed_at' => now(),
        ]);

        $appointment = $review->appointment;
        if ($appointment) {
            $this->staffNotifier->appointment(
                $appointment,
                'Review updated',
                "{$this->clientName($appointment)} updated their review from {$before} to {$payload['rating']} stars (booking {$appointment->appointment_number}).",
                null,
                'Appointment',
                false,
            );
        }

        return new ClientReviewResource($review->fresh()->load('appointment.branch.business', 'appointment.services.serviceVariant.service'));
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

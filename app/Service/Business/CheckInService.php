<?php

namespace App\Service\Business;

use App\Enums\AppointmentServiceStatus;
use App\Enums\AppointmentStatus;
use App\Enums\QueueStatus;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\Queue;
use App\Models\User;
use App\Repository\Business\AppointmentRepository;
use App\Repository\Business\QueueRepository;
use App\Repository\SpaBusinessRepository;
use App\Service\Client\BookingPolicy;
use App\Support\Branches\BusinessDay;
use App\Support\ClientNotificationLink;
use App\Support\Visits\FrontDeskPolicy;
use App\Support\Visits\ReservationHold;
use App\Support\Visits\StaleVisit;
use App\Support\Platform\Suspension;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A client arriving: checking in (asking the desk when they are early, late or would run past
 * closing), joining the line, and the desk calling, skipping and recalling them. QueueService
 * reads the line; every change to it happens here.
 *
 * Split out of AppointmentService (which still hands every call on, for its older callers).
 */
class CheckInService
{
    public function __construct(
        private AppointmentRepository $appointmentRepository,
        private QueueRepository $queueRepository,
        private SpaBusinessRepository $spaBusinessRepository,
        private AppointmentAvailabilityService $availabilityService,
        private ClientNotifier $clientNotifier,
    ) {}

    // ── Status transitions ──────────────────────────────────────────────
    // No client-confirmation step exists in this workflow — an appointment
    // lands on Scheduled at creation and moves directly to Checked In.

    // Check-in flips the header status, records check_in_at, and immediately
    // places the appointment on the queue (addToQueueInternal is a no-op if
    // it's already queued — see createAppointment()'s walk-in path, which
    // queues at creation time instead).
    // $now: a booking for a later day whose client is here today — moved to
    // today at this moment, then checked in like any other. Booked "now", they
    // join the back of the line (ReservationHold::queuedAt), behind everyone
    // already waiting.
    // $place: where an early or late reservation joins the line ('now' or
    // 'last' — from now; 'booked' — at its booked time). $pastClosing: take a
    // visit that would run past closing anyway. When either is needed and not
    // given, the answer is a 409 `check_in_choice` for the desk to pick from.
    public function checkIn(User $user, string $uuid, bool $now = false, ?string $place = null, bool $pastClosing = false)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        $work = function () use ($user, $branchIds, $uuid, $now, $place, $pastClosing) {
            $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $branchIds);

            if ($stale = StaleVisit::refusal($appointment)) {
                return $stale;
            }
            // Nobody joins the line before the branch opens (its hours, Branch Settings → Operations).
            if ($closed = $this->closedNowRefusal($appointment->spa_branch_id, 'nobody can be checked in yet')) {
                return $closed;
            }
            // Here today for a later day's booking: it becomes today's, from now.
            if ($now && $appointment->status === AppointmentStatus::Scheduled->value
                && $appointment->appointment_date->toDateString() > now()->toDateString()) {
                $from = $appointment->appointment_date->format('M j').' '.Carbon::parse($appointment->appointment_time)->format('g:i A');
                $appointment->update([
                    'appointment_date' => now()->toDateString(),
                    'appointment_time' => now()->format('H:i'),
                    'remarks' => trim(($appointment->remarks ? $appointment->remarks.' · ' : '')."Came early — moved from {$from} and checked in now by {$user->displayName()}"),
                ]);
                // Whether it still fits today's hours is asked below, with every check-in.
            }
            // A suspended branch winds down: checking someone in starts something new.
            if ($suspended = Suspension::refusal($appointment->branch)) {
                return $suspended;
            }

            if (! $appointment->canTransitionTo(AppointmentStatus::CheckedIn->value)) {
                return response()->json(['message' => 'Only a scheduled appointment can be checked in.'], 422);
            }
            // Only on the day of the booking: a client who comes on another day is
            // booked for that day instead (move the booking, then check them in).
            if ($appointment->appointment_date->toDateString() > now()->toDateString()) {
                return response()->json(['message' => 'This booking is for '.$appointment->appointment_date->format('D, M j')
                    .'. To serve them today, use "Check in now" (they join the back of the line), or move the booking.'], 422);
            }
            // One visit at a time: not while another visit of theirs is in the queue or on the floor.
            $here = $appointment->client_id ? $this->visitHereNow($appointment->client_id) : null;
            if ($here && $here->id !== $appointment->id) {
                return response()->json(['message' => 'This client is already '
                    .($here->status === AppointmentStatus::InService->value ? 'being served' : 'in the queue')
                    .' on another visit. Finish or cancel that one first.'], 422);
            }

            // The queue's size limit (Settings → Booking & Policies).
            $gate = app(PolicyGate::class);
            $overrides = $appointment->branch ? $gate->check($user, FrontDeskPolicy::queue(
                BookingPolicy::for($appointment->branch),
                $this->queueRepository->waitingCount($appointment->spa_branch_id, $appointment->appointment_date->format('Y-m-d')),
            )) : [];
            if (! is_array($overrides)) {
                return $overrides;
            }

            // Where they join the line (ReservationHold). In time (from the
            // booked time to the end of the grace period): their booked place.
            // Early: the desk picks now or the booked time — unless moving up
            // would take another booking's turn with the same staff. Late: the
            // desk picks the back of the line or the booked place.
            $queuedAt = null;
            $note = null;
            $remarks = [];
            $client = trim(($appointment->client?->first_name ?? '').' '.($appointment->client?->last_name ?? '')) ?: 'This client';
            // Moved to today just now: plainly the back of the line.
            if ($now) {
                $queuedAt = now();
            } elseif ($appointment->appointment_type !== 'Walk-in' && $appointment->branch) {
                $bookedStart = Carbon::parse($appointment->appointment_date->format('Y-m-d').' '.$appointment->appointment_time);
                $grace = (int) (BookingPolicy::for($appointment->branch)['reservation_grace_minutes'] ?? ReservationHold::DEFAULT_GRACE_MINUTES);
                $booked = $bookedStart->format('g:i A');
                $arrival = ReservationHold::arrival($bookedStart, $grace, now());

                if ($arrival === 'early') {
                    if ($conflict = $this->availabilityService->aheadOfBooking($appointment, now())) {
                        $place = 'booked';
                        $note = $conflict;
                    } elseif (! in_array($place, ['now', 'booked'], true)) {
                        self::askCheckIn('early', "{$client} is early — booked for {$booked}. Queue them now, or at their booked time?", [
                            ['key' => 'now', 'label' => 'Queue now', 'hint' => 'Ahead of everyone who comes in after '.now()->format('g:i A').'.'],
                            ['key' => 'booked', 'label' => "Queue at booking time ({$booked})", 'hint' => "Takes their {$booked} place in line."],
                        ]);
                    }
                    $queuedAt = $place === 'now' ? now() : $bookedStart;
                } elseif ($arrival === 'late') {
                    if (! in_array($place, ['last', 'booked'], true)) {
                        $held = ReservationHold::endsAt($bookedStart, $grace)->format('g:i A');
                        self::askCheckIn('late', "{$client} is late — booked for {$booked} and held until {$held}. Where do they join the line?", [
                            ['key' => 'last', 'label' => 'Queue last', 'hint' => 'Behind everyone who is here now.'],
                            ['key' => 'booked', 'label' => "Keep their booked place ({$booked})", 'hint' => 'Ahead of everyone who came in after '.$booked.'.'],
                        ]);
                    }
                    $queuedAt = $place === 'booked' ? $bookedStart : now();
                    $remarks[] = 'Arrived '.now()->format('g:i A').', past the hold — '
                        .($place === 'booked' ? "kept the {$booked} place" : 'queued last');
                } else {
                    $queuedAt = $bookedStart;
                }
            }

            // Whether the visit can still finish before closing: the desk may
            // take them anyway, or not take them (the web then cancels it).
            if ($past = $this->availabilityService->pastClosing($appointment)) {
                if (! $pastClosing) {
                    self::askCheckIn('closing', "{$client}'s visit would end about {$past['ends']->format('g:i A')}, after closing at {$past['closes']->format('g:i A')}. Take them anyway?", [
                        ['key' => 'allow', 'label' => 'Take them anyway', 'hint' => 'Check them in; the visit runs past closing.'],
                        ['key' => 'cancel', 'label' => "Don't take them", 'hint' => 'Cancels the booking.'],
                    ]);
                }
                $remarks[] = 'Taken past closing';
            }

            $appointment->update(['status' => AppointmentStatus::CheckedIn->value, 'check_in_at' => now()]
                + ($remarks ? ['remarks' => trim(($appointment->remarks ? $appointment->remarks.' · ' : '').implode(' · ', $remarks)." (by {$user->displayName()})")] : []));
            $this->addToQueueInternal($appointment, $queuedAt);
            $gate->record($appointment, $overrides, $user);

            $queueNumber = $appointment->fresh('queue')->queue?->queue_number;
            $this->clientNotifier->appointment(
                $appointment,
                "You're checked in",
                "You're checked in at {$appointment->branch?->branch_name}."
                    .($queueNumber ? " Your queue number is {$queueNumber}." : ''),
            );

            // `note`: why an early arrival kept its booked place, when it did.
            return (new AppointmentResource($this->appointmentRepository->findByUuid($uuid)))
                ->additional($note ? ['note' => $note] : []);
        };

        // A refusal leaves nothing behind: "Check in now" moves the booking to
        // today before the checks that may still refuse it (already here on
        // another visit, the queue's size, the closing question).
        return DB::transaction(function () use ($work) {
            $result = $work();
            if ($result instanceof JsonResponse && $result->getStatusCode() >= 400) {
                throw new HttpResponseException($result);
            }

            return $result;
        });
    }

    // Stops a check-in to ask the desk (409 `check_in_choice`); thrown, not
    // returned, so the transaction rolls back whatever was already changed
    // (a booking "Check in now" had moved to today).
    private static function askCheckIn(string $kind, string $message, array $options): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'check_in_choice' => ['kind' => $kind, 'message' => $message, 'options' => $options],
        ], 409));
    }

    // ── Queue actions (front-desk manual control, not lifecycle side
    // effects) ────────────────────────────────────────────────────────────
    // Unlike every other queue write in this class (check-in/start/complete/
    // cancel all move the ticket as a side effect of an appointment status
    // change), calling/skipping/recalling is a deliberate front-desk action
    // on the ticket itself. Still routed through here rather than
    // QueueService (which stays read-only, see this class's own opening
    // comment) to keep the "exactly one write path" rule intact.
    // Calling is for a booked client who has not arrived: it alerts them in the
    // app to come to the spa (a client who is here is checked in and simply has
    // their service started). Nothing is stored, so a second press within a
    // couple of minutes is told it was just sent rather than alerting twice.
    public function callQueue(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));

        if ($appointment->status !== AppointmentStatus::Scheduled->value) {
            return response()->json(['message' => 'Only a client who has not checked in yet can be called. A client who is here is checked in: start their service.'], 422);
        }
        if ($appointment->appointment_date->format('Y-m-d') !== StaleVisit::today()) {
            return response()->json(['message' => 'Only a booking for today can be called.'], 422);
        }
        if ($stale = StaleVisit::refusal($appointment)) {
            return $stale;
        }

        // Calling is an alert in the client's app; a client without the app
        // has nothing to be alerted on, and is called by phone.
        if (! $appointment->client?->usesApp()) {
            return response()->json(['message' => 'Only clients who use the Servora app can be called. Phone them instead.'], 422);
        }

        if (! Cache::add("appointment-called:{$appointment->id}", true, now()->addMinutes(2))) {
            return response()->json(['message' => 'This client was called a moment ago. Give them a couple of minutes.'], 422);
        }
        // For the client's app until the day ends: "The spa is ready for you" on the booking's queue screen.
        Cache::put(Queue::calledKey($appointment->id), now()->toIso8601String(), now()->endOfDay());

        $this->clientNotifier->appointment(
            $appointment,
            ClientNotificationLink::QUEUE_CALL_TITLE,
            "Please come to {$appointment->branch?->branch_name} and check in with the Front Officer for booking {$appointment->appointment_number}. Tap to see the queue.",
        );

        return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
    }

    // Moves a waiting or called client down the line (see below). $behind is the booking
    // (uuid) they go behind, or "back" for the end of the line; left out, they move down one
    // place, as before (the mobile app and older screens).
    public function skipQueue(User $user, string $uuid, ?string $behind = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        $queue = $appointment->queue;

        if (! $queue || ! in_array($queue->queue_status, [QueueStatus::Waiting->value, QueueStatus::Called->value], true)) {
            return response()->json(['message' => 'Only a client waiting in line can be moved down.'], 422);
        }
        // Someone whose service has begun (or is done) is being served: not skipped.
        if ($appointment->services()->whereIn('status', [AppointmentServiceStatus::InProgress->value, AppointmentServiceStatus::Completed->value])->exists()) {
            return response()->json(['message' => 'A service for this client has already started, so they can\'t be moved down.'], 422);
        }
        // A home visit is not part of the line at the spa.
        if ($appointment->isHomeService()) {
            return response()->json(['message' => 'A home visit isn\'t in the line at the spa, so it can\'t be moved down.'], 422);
        }

        // Skipping moves them down one place: they swap with whoever is right behind them,
        // so ticket 8 skipped goes after ticket 9. (Before the queued_at column exists, the
        // old behaviour: they step out of the line until put back.)
        if (! Queue::ready()) {
            $this->queueRepository->updateStatus($queue, QueueStatus::Skipped->value);

            return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
        }

        $walkInsFirst = $appointment->branch ? (bool) BookingPolicy::for($appointment->branch)['walk_ins_first_priority'] : false;

        // Behind a chosen client (or to the back): only those behind them now, of their own
        // kind with "prioritize walk-ins" on. They go right after the one chosen; nobody else moves.
        if ($behind !== null && $behind !== '') {
            $line = $this->queueRepository->allBehind($queue->loadMissing('appointment'), $walkInsFirst)->values();
            if ($line->isEmpty()) {
                return response()->json(['message' => 'No one is behind this client, so they are already last in line.'], 422);
            }
            $at = $behind === 'back'
                ? $line->count() - 1
                : $line->search(fn (Queue $q) => $q->appointment?->uuid === $behind);
            if ($at === false) {
                return response()->json(['message' => 'That client is no longer behind this one in line. Refresh the queue and try again.'], 422);
            }

            $this->queueRepository->placeAfter($queue, $line[$at], $line[$at + 1] ?? null);
            Cache::put(Queue::movedDownKey($queue->id), now()->toIso8601String(), now()->addMinutes(30));

            return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
        }

        $next = $this->queueRepository->nextBehind($queue->loadMissing('appointment'), $walkInsFirst);
        if (! $next) {
            return response()->json(['message' => 'No one is behind this client, so they are already last in line.'], 422);
        }

        $this->queueRepository->swapPlaces($queue, $next);
        Cache::put(Queue::movedDownKey($queue->id), now()->toIso8601String(), now()->addMinutes(30));

        return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
    }

    // Brings a Called/Skipped ticket back into the active line — e.g. a
    // skipped client shows back up, or Call was a misclick. Always lands
    // back on Waiting; the line is ordered by each ticket's place, queued_at
    // (QueueRepository::listForBranchDate, Queue::lineKey), so recalling doesn't move the
    // ticket to the back of the line, it just clears the Called/Skipped flag.
    public function recallQueue(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        $queue = $appointment->queue;

        if (! $queue || ! in_array($queue->queue_status, [QueueStatus::Called->value, QueueStatus::Skipped->value], true)) {
            return response()->json(['message' => 'Only a client who was set aside can be put back in line.'], 422);
        }

        $this->queueRepository->updateStatus($queue, QueueStatus::Waiting->value, ['called_at' => null]);

        return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
    }

    // ── Queue ────────────────────────────────────────────────────────────

    // Places an already-checked-in appointment on today's queue. Called from
    // checkIn() and from createAppointment()'s walk-in path — never exposed
    // directly, since every checked-in appointment is queued by the time
    // either of those returns. No-op if a queue row already exists so callers
    // don't need to guard against calling it twice.
    // The branch is closed right now (outside its hours, or a closed day —
    // App\Support\Branches\BusinessDay): why $what, and when it opens. Null while open.
    public function closedNowRefusal(int $branchId, string $what)
    {
        $week = BusinessDay::week($branchId);
        if (BusinessDay::isOpen($week, now())) {
            return null;
        }
        $opens = BusinessDay::opensNext($week, now());

        return response()->json(['message' => "The branch isn't open right now, so {$what}."
            .($opens ? ' It opens '.($opens->isToday() ? 'at '.$opens->format('g:i A') : $opens->format('D, M j \a\t g:i A')).'.' : '')], 422);
    }

    // The client's visit that is here right now — checked in (in the queue)
    // or in service — on a day that isn't over (StaleVisit), if any.
    public function visitHereNow(int $clientId): ?Appointment
    {
        return Appointment::where('client_id', $clientId)
            ->whereIn('status', [AppointmentStatus::CheckedIn->value, AppointmentStatus::InService->value])
            ->whereDate('appointment_date', '>=', StaleVisit::today())
            ->with('queue')
            ->first();
    }

    // $queuedAt: its place in the line, when that isn't "now" (a reservation
    // arriving in time keeps its booked time — see checkIn()).
    public function addToQueueInternal(Appointment $appointment, ?CarbonInterface $queuedAt = null): void
    {
        if ($appointment->queue) {
            return;
        }

        $date = $appointment->appointment_date->format('Y-m-d');

        $this->queueRepository->create([
            'appointment_id' => $appointment->id,
            'spa_branch_id' => $appointment->spa_branch_id,
            'appointment_date' => $date,
            'queue_number' => $this->queueRepository->nextQueueNumber(
                $appointment->spa_branch_id, $date,
                $appointment->branch ? BookingPolicy::for($appointment->branch)['queue_number_format'] : 'alpha-numeric',
            ),
        ] + ($queuedAt && Queue::ready() ? ['queued_at' => $queuedAt] : []));
    }

    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchIdsForUser($user);
    }

    // An add-on is never handled as a service of its own: it has no therapist,
    // room, Start or Complete — its main service's are its own.
    private function addonFollows(AppointmentServiceItem $service, string $what)
    {
        return $service->isAddon()
            ? response()->json(['message' => "An add-on {$what} with its main service."], 422)
            : null;
    }

    // time | procedure | facility — a service saved before types existed is
    // time-based (App\Support\Catalog\ServiceKind).
    private function kindOf(AppointmentServiceItem $service): string
    {
        return $service->kind();
    }
}

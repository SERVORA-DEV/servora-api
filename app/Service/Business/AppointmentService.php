<?php

namespace App\Service\Business;

use App\Http\Resources\AppointmentResource;
use App\Http\Resources\PaymentResource;
use App\Models\Addon;
use App\Models\Appointment;
use App\Models\Queue;
use App\Models\AppointmentPackage;
use App\Models\AppointmentServiceItem;
use App\Models\Attendance;
use App\Models\Billing;
use App\Models\BranchPackage;
use App\Models\BranchSchedule;
use App\Models\BranchService;
use App\Models\Client;
use App\Models\Facility;
use App\Models\Package;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusinessSetting;
use App\Models\Staff;
use App\Http\Resources\Client\ClientAppointmentResource;
use App\Service\Client\BookingPolicy;
use App\Service\Client\BookingSlotService;
use App\Service\Client\ClientBookingService;
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Support\ReservationHold;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use App\Repository\Business\AppointmentRepository;
use App\Repository\Business\AppointmentServiceRepository;
use App\Repository\Business\ClientRepository;
use App\Repository\Business\QueueRepository;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\Business\TherapistAssignmentRepository;
use App\Repository\BillingRepository;
use App\Repository\PaymentRepository;
use App\Repository\SpaBusinessRepository;
use App\Support\ClientNotificationLink;
use App\Support\ClientProfile;
use App\Support\FrontDeskPolicy;
use App\Support\History;
use App\Support\HomeVisitStart;
use App\Support\HourlyCapacity;
use App\Support\PackageShare;
use App\Support\RotationTurn;
use App\Support\ServiceAddon;
use App\Support\ServiceKind;
use App\Support\ServiceStartRules;
use App\Support\ServiceVoidRules;
use App\Support\StaleVisit;
use App\Support\Suspension;
use App\Support\StaffGender;
use Illuminate\Support\Facades\DB;

// Orchestrates the full appointment lifecycle end to end: creation,
// check-in, service/package selection, per-service multi-therapist and room
// assignment, queueing, service execution, cancellation/no-show, and
// billing/payment handoff. Billing/payment steps stay here rather than in a
// separate service class since they're just later steps of the same
// lifecycle and reuse the existing shared BillingRepository/PaymentRepository
// as-is (no parallel billing system). Queue *mutations* also stay here
// (QueueService is read-only) — every write to an appointment's state goes
// through this one class, which is what makes the row-locked transactional
// guards below (start/complete/bill) actually meaningful.
class AppointmentService
{
    private AppointmentRepository $appointmentRepository;
    private AppointmentServiceRepository $appointmentServiceRepository;
    private TherapistAssignmentRepository $therapistAssignmentRepository;
    private QueueRepository $queueRepository;
    private ClientRepository $clientRepository;
    private ClientService $clientService;
    private SpaBusinessRepository $spaBusinessRepository;
    private SpaBranchRepository $spaBranchRepository;
    private AppointmentAvailabilityService $availabilityService;
    private BillingRepository $billingRepository;
    private PaymentRepository $paymentRepository;
    private AttendanceStatusCalculator $attendanceStatusCalculator;
    private TherapistQueueCalculator $therapistQueueCalculator;
    private ClientNotifier $clientNotifier;
    private StaffNotifier $staffNotifier;
    private BillingService $billingService;

    public function __construct(
        AppointmentRepository $appointmentRepository,
        AppointmentServiceRepository $appointmentServiceRepository,
        TherapistAssignmentRepository $therapistAssignmentRepository,
        QueueRepository $queueRepository,
        ClientRepository $clientRepository,
        ClientService $clientService,
        SpaBusinessRepository $spaBusinessRepository,
        SpaBranchRepository $spaBranchRepository,
        AppointmentAvailabilityService $availabilityService,
        BillingRepository $billingRepository,
        PaymentRepository $paymentRepository,
        AttendanceStatusCalculator $attendanceStatusCalculator,
        TherapistQueueCalculator $therapistQueueCalculator,
        ClientNotifier $clientNotifier,
        StaffNotifier $staffNotifier,
        BillingService $billingService,
    ) {
        $this->billingService = $billingService;
        $this->appointmentRepository = $appointmentRepository;
        $this->appointmentServiceRepository = $appointmentServiceRepository;
        $this->therapistAssignmentRepository = $therapistAssignmentRepository;
        $this->queueRepository = $queueRepository;
        $this->clientRepository = $clientRepository;
        $this->clientService = $clientService;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->spaBranchRepository = $spaBranchRepository;
        $this->availabilityService = $availabilityService;
        $this->billingRepository = $billingRepository;
        $this->paymentRepository = $paymentRepository;
        $this->attendanceStatusCalculator = $attendanceStatusCalculator;
        $this->therapistQueueCalculator = $therapistQueueCalculator;
        $this->clientNotifier = $clientNotifier;
        $this->staffNotifier = $staffNotifier;
    }

    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchIdsForUser($user);
    }

    // ── Listing / detail ────────────────────────────────────────────────

    public function listAppointments(User $user, array $filters, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $collection = $this->appointmentRepository->paginateForBranches($this->branchIds($user), $filters, $perPage);
        return AppointmentResource::collection($collection);
    }

    // Backs the owner/manager "Billing & Payments" page — every appointment
    // billing across the caller's branches (not the subscription billings
    // System > Transactions already covers). See BillingListResource for
    // the flattened shape this returns.
    public function listBillings(User $user, array $filters, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $collection = $this->billingRepository->paginateAppointmentBillingsForBranches($this->branchIds($user), $filters, $perPage);
        return \App\Http\Resources\BillingListResource::collection($collection);
    }

    public function getAppointment(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        return new AppointmentResource($appointment);
    }

    // ── Creation ─────────────────────────────────────────────────────────

    // Minimum required payload: spa_branch_uuid + appointment_date +
    // appointment_time (+ client_uuid xor client{...}) — services and
    // requested_therapist_uuid are optional (rule 1). A failed requested-
    // therapist assignment never fails the whole creation — it's surfaced
    // as a warning instead (rule 6: "clearly show unavailable... continue
    // without assigning one yet").
    public function createAppointment(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branches = $this->spaBusinessRepository->branchesForUser($user);
        $branchIds = $branches->pluck('id')->all();

        return DB::transaction(function () use ($user, $business, $branches, $branchIds, $payload) {
            if (! empty($payload['spa_branch_uuid'])) {
                $branch = $this->spaBranchRepository->findByUuidForBranches($payload['spa_branch_uuid'], $branchIds);
            } elseif ($branches->count() === 1) {
                // front_officer/manager: exactly one accessible branch,
                // their UI has no branch picker at all.
                $branch = $branches->first();
            } else {
                return response()->json(['message' => 'spa_branch_uuid is required.'], 422);
            }

            // A suspended branch winds down: nothing new is booked there.
            if ($suspended = Suspension::refusal($branch)) {
                return $suspended;
            }

            // A walk-in joins the line at once: not before the branch opens.
            if (($payload['appointment_type'] ?? null) === 'Walk-in'
                && ($closed = $this->closedNowRefusal($branch->id, 'a walk-in can\'t be added yet'))) {
                return $closed;
            }
            // A walk-in is here now — on the server's clock, whatever the
            // desk's laptop says the time is.
            if (($payload['appointment_type'] ?? null) === 'Walk-in') {
                $payload['appointment_date'] = now()->toDateString();
                $payload['appointment_time'] = now()->format('H:i');
            }

            // The owner's (or this branch's) booking rules, as the desk is
            // held to them: a reservation against the notice, days-ahead and
            // size limits; a walk-in against the size of the queue. Refused
            // unless overridden with a reason (PolicyGate) — kept below once
            // the booking exists.
            $policy = BookingPolicy::for($branch);
            $gate = app(PolicyGate::class);
            $policyClient = null;
            if (($payload['appointment_type'] ?? 'Reservation') === 'Walk-in') {
                $problems = FrontDeskPolicy::queue($policy, $this->queueRepository->waitingCount($branch->id, now()->toDateString()));
            } else {
                if (! empty($payload['client_uuid']) && $policy['no_show_policy'] === 'suspend') {
                    $known = $this->clientRepository->findByUuidForBusiness($payload['client_uuid'], $business->id, $this->clientService->branchScope($user));
                    $policyClient = ['name' => trim("{$known->first_name} {$known->last_name}") ?: 'This client', 'no_shows' => $this->noShowCount($known->id)];
                }
                $problems = FrontDeskPolicy::reservation(
                    $policy,
                    Carbon::parse("{$payload['appointment_date']} {$payload['appointment_time']}", 'Asia/Manila'),
                    BookingPolicy::now(),
                    count($payload['services'] ?? []) + (empty($payload['package_uuid']) ? 0 : 1),
                    (int) $this->requestedMinutes($payload),
                    $policyClient,
                    $this->requestedLongestMinutes($payload),
                    ! empty($payload['package_uuid']),
                );
            }
            $overrides = $gate->check($user, $problems);
            if (! is_array($overrides)) {
                return $overrides;
            }

            // Home service: the place has to be inside this branch's area,
            // and a walk-in is by definition someone standing at the desk.
            $home = null;
            if (HomeServiceRules::isHome($payload)) {
                if (($payload['appointment_type'] ?? null) === 'Walk-in') {
                    return response()->json(['message' => 'A walk-in can\'t be a home service. Add it as a reservation instead.'], 422);
                }
                $home = HomeServiceRules::check($branch, $payload);
                if (! $home['ok']) {
                    return response()->json(['message' => $home['reason']], 422);
                }
                if (! empty($payload['requested_therapist_uuid'])
                    && ($asked = $this->resolveStaffForBranch($payload['requested_therapist_uuid'], $branch->id))
                    && ($refusal = HomeServiceRules::staffRefusal($asked))) {
                    return response()->json(['message' => $refusal], 422);
                }
            }

            $scheduleCheck = $this->availabilityService->branchIsOpen(
                $branch->id, $payload['appointment_date'], $payload['appointment_time'],
                fn () => $this->requestedMinutes($payload),
            );
            if (! $scheduleCheck['ok']) {
                return response()->json(['message' => $scheduleCheck['reason']], 422);
            }

            // The spa's bookings-per-hour limit, and the 5-minute marks: a
            // hard stop for a reservation (a walk-in is here now and is never
            // refused by it). The branch row is held while the count is read,
            // so two desks can't both take the last place in the hour.
            if (($payload['appointment_type'] ?? 'Reservation') !== 'Walk-in') {
                SpaBranch::whereKey($branch->id)->lockForUpdate()->first();
                if ($hourProblem = HourlyCapacity::problemFor($branch, $payload['appointment_date'], $payload['appointment_time'])) {
                    return response()->json(['message' => $hourProblem], 422);
                }
            }

            $client = ! empty($payload['client_uuid'])
                ? $this->clientRepository->findByUuidForBusiness($payload['client_uuid'], $business->id, $this->clientService->branchScope($user))
                : $this->clientService->findOrCreate($business->id, $payload['client'] ?? [], $branch->id);

            // A client already on file: what their record was missing, sent
            // with the booking.
            if (! empty($payload['client_uuid']) && ! empty($payload['client_update'])) {
                $client = $this->clientService->completeProfile($client, $payload['client_update']);
            }

            // A reservation needs a way to reach the client (a change of plan,
            // a reminder, a no-show): a phone number on record, or given with
            // the booking just above. A walk-in is here, so it can do without.
            // abort() rolls back a client record made a moment ago.
            abort_if(($payload['appointment_type'] ?? 'Reservation') !== 'Walk-in' && blank($client->phone_number), 422,
                "Add the client's phone number to book a reservation.");

            // Someone is going to their door: a phone number and the gender
            // come first (App\Support\ClientProfile). abort() rolls back a
            // client record made a moment ago.
            if ($home && Client::hasProfile()) {
                $missing = ClientProfile::missingForHome(['phone_number' => $client->phone_number, 'gender' => $client->genderValue()]);
                abort_if((bool) $missing, 422, ClientProfile::homeMessage($missing));
            }

            // One visit at a time: a client already here today — in the queue
            // or being served on the floor — can't be booked again for today
            // until that visit is finished or cancelled. (A later day is fine.)
            if ($payload['appointment_date'] === now()->toDateString() && ($here = $this->visitHereNow($client->id))) {
                $name = trim("{$client->first_name} {$client->last_name}") ?: 'This client';
                $where = $here->status === Appointment::STATUS_IN_SERVICE
                    ? 'being served right now'
                    : 'already in the queue' . ($here->queue?->queue_number ? " (ticket {$here->queue->queue_number})" : '');

                return response()->json(['message' => "{$name} is {$where}. Finish or cancel that visit before adding another one today."], 422);
            }

            $appointmentType = $payload['appointment_type'] ?? 'Reservation';
            // A walk-in has already arrived by definition — skip the
            // Scheduled/manual-check-in step entirely and land straight on
            // Checked In + queued, mirroring what checkIn() would otherwise
            // do a moment later.
            $isWalkIn = $appointmentType === 'Walk-in';

            $appointment = $this->appointmentRepository->create([
                'spa_branch_id' => $branch->id,
                'client_id' => $client->id,
                'appointment_number' => $this->appointmentRepository->generateAppointmentNumber(),
                'appointment_date' => $payload['appointment_date'],
                'appointment_time' => $payload['appointment_time'],
                'appointment_type' => $appointmentType,
                'source' => $payload['source'] ?? 'Front Desk',
                'status' => $isWalkIn ? Appointment::STATUS_CHECKED_IN : Appointment::STATUS_SCHEDULED,
                'check_in_at' => $isWalkIn ? now() : null,
                'remarks' => $payload['remarks'] ?? null,
            ] + ($home ? HomeServiceRules::attributes($payload, $home) : []) + $this->startAttributes($payload, (bool) $home));

            $warnings = [];
            $this->rememberRequestedTherapist($appointment, $branch->id, $payload['requested_therapist_uuid'] ?? null);

            foreach (array_values($payload['services'] ?? []) as $index => $item) {
                $service = $this->addServiceInternal($appointment, $branch->id, $item, null, $index + 1);

                if (! empty($payload['requested_therapist_uuid'])) {
                    $this->applyRequestedTherapist($appointment, $branch->id, $service, $payload['requested_therapist_uuid'], $warnings);
                }
            }

            // A package booked with it: its services go on as lines of their
            // own (see addPackage()), each offered to the requested therapist.
            if (! empty($payload['package_uuid'])) {
                $appointmentPackage = $this->addPackageInternal($appointment, $business->id, $payload['package_uuid'], 1);

                if (! empty($payload['requested_therapist_uuid'])) {
                    foreach ($appointment->services()->where('source_appointment_package_id', $appointmentPackage->id)->get() as $service) {
                        $this->applyRequestedTherapist($appointment, $branch->id, $service, $payload['requested_therapist_uuid'], $warnings);
                    }
                }
            }

            // Every service on a home visit must be one the owner marked for
            // home service. abort() rolls the booking back.
            if ($home && ($blocked = HomeServiceRules::ineligibleServices($appointment))) {
                abort(422, HomeServiceRules::ineligibleMessage($blocked));
            }

            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

            if ($isWalkIn) {
                $this->addToQueueInternal($appointment);
            }

            $appointment->setRelation('branch', $branch);
            $gate->record($appointment, $overrides, $user);
            $clientName = trim("{$client->first_name} {$client->last_name}") ?: 'A client';
            $this->staffNotifier->appointment(
                $appointment,
                $isWalkIn ? 'Walk-in added to queue' : 'New booking',
                $isWalkIn
                    ? "{$clientName} walked in and joined the queue. Booking no. {$appointment->appointment_number}."
                    : "{$clientName} was booked for "
                        . Carbon::parse("{$payload['appointment_date']} {$payload['appointment_time']}")->format('D, M j \\a\\t g:i A')
                        . ". Booking no. {$appointment->appointment_number}.",
                'on_new_booking',
                exceptUserId: $user->id,
            );

            $resource = new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));

            return $warnings ? $resource->additional(['warnings' => $warnings]) : $resource;
        });
    }

    // Mobile self-booking by a logged-in client (role=client). Unlike
    // createAppointment(), the branch comes from the public branch uuid (not
    // a staff user's own branches), the client record is the caller's own
    // (see ClientService::findOrCreateForUser), and the type/source/status
    // are fixed server-side — nothing price- or workflow-related is taken
    // from the request. Same branch-hours gate and requested-therapist
    // warning behaviour as the front-desk path.
    public function createClientAppointment(User $user, array $payload)
    {
        $branch = $this->spaBranchRepository->publicFindByUuid($payload['spa_branch_uuid']);
        $policy = BookingPolicy::for($branch);

        // The owner's booking rules (online booking on, lead time, how far
        // ahead) — stored in Settings for a long time but only enforced here
        // now; see BookingPolicy.
        $startsAt = Carbon::parse("{$payload['appointment_date']} {$payload['appointment_time']}");
        if ($problem = BookingPolicy::startTimeProblem($policy, $startsAt)) {
            return response()->json(['message' => $problem], 422);
        }

        // Home service: offered here, and the pinned address is in the area.
        $home = null;
        if (HomeServiceRules::isHome($payload)) {
            $home = HomeServiceRules::check($branch, $payload);
            if (! $home['ok']) {
                return response()->json(['message' => $home['reason']], 422);
            }
        }

        // Only branches ready for bookings (services, hours, a therapist… —
        // see MarketplaceReadiness) are listed; a stale link can't book one.
        if ($suspended = Suspension::refusal($branch)) {
            return $suspended;
        }
        if (! MarketplaceReadiness::isBookable($branch)) {
            return response()->json(['message' => MarketplaceReadiness::NOT_BOOKABLE], 422);
        }

        // No-show policy "suspend after N": booking from the app is paused
        // for a client with that many missed bookings here — the desk can
        // still book for them (with an override).
        if ($policy['no_show_policy'] === 'suspend') {
            $mine = \App\Models\Client::where('spa_business_id', $branch->spa_business_id)->where('user_id', $user->id)->value('id');
            if ($mine && FrontDeskPolicy::clientSuspended($policy, $this->noShowCount($mine))) {
                return response()->json(['message' => BookingPolicy::missedBookingsNotice($policy, $this->noShowCount($mine), (string) $branch->business?->business_name)['message']], 422);
            }
        }

        // The most bookings one client may have in a day here (Booking &
        // Policies; any branch counts): beyond it they book another day.
        $dayLimit = $policy['max_bookings_per_client_per_day'];
        if ($this->clientBookingsOn($user, $branch->spa_business_id, $payload['appointment_date']) >= $dayLimit) {
            return response()->json(['message' => self::dayLimitMessage($dayLimit, $payload['appointment_date'])], 422);
        }

        $serviceCount = count($payload['services'] ?? []) + (empty($payload['package_uuid']) ? 0 : 1);
        $maxServices = $policy['max_services_per_booking'];
        if ($maxServices > 0 && $serviceCount > $maxServices) {
            return response()->json(['message' => "You can book up to {$maxServices} services at a time."], 422);
        }

        // A stale menu (a service or package removed since the app loaded
        // it) is a message, not "this spa is no longer accepting bookings".
        foreach ($payload['services'] ?? [] as $item) {
            if (! ServiceVariant::where('uuid', $item['service_variant_uuid'])->exists()) {
                return response()->json(['message' => 'One of the services you picked is no longer offered. Please go back and choose again.'], 422);
            }
        }
        if (! empty($payload['package_uuid']) && ! Package::where('uuid', $payload['package_uuid'])->exists()) {
            return response()->json(['message' => 'That package is no longer offered. Please go back and choose again.'], 422);
        }

        // Inside the branch's hours, and over by closing time.
        $scheduleCheck = $this->availabilityService->branchIsOpen($branch->id, $payload['appointment_date'], $payload['appointment_time'], fn () => $this->requestedMinutes($payload));
        if (! $scheduleCheck['ok']) {
            return response()->json(['message' => $scheduleCheck['reason']], 422);
        }

        // A booking of theirs already at this day and time (any branch of the
        // business) is a warning, not a refusal: the app shows it before they
        // confirm and sends acknowledge_overlap when they book anyway. Without
        // it (a retried request after a slow network, or an app that did not
        // know) the booking is held back with a code the app understands.
        $overlap = Appointment::with('branch')
            ->whereHas('branch', fn ($q) => $q->where('spa_business_id', $branch->spa_business_id))
            ->whereDate('appointment_date', $payload['appointment_date'])
            ->where('appointment_time', 'like', substr($payload['appointment_time'], 0, 5).'%')
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
            ->whereHas('client', fn ($q) => $q->where('user_id', $user->id))
            ->first();
        if ($overlap && empty($payload['acknowledge_overlap'])) {
            $at = Carbon::parse($payload['appointment_date'] . ' ' . $payload['appointment_time'])->format('g:i A');

            return response()->json([
                'message' => "You already have a booking at {$at} that day ({$overlap->branch?->branch_name}). Tap Confirm Booking again to book this one too.",
                'code' => 'overlaps_existing_booking',
            ], 409);
        }

        // Show therapist profiles off, or Client Can Choose Staff off (for the
        // business or this branch): the app offers no therapist picker, so a
        // requested therapist (an older app, a direct call) is dropped.
        // Who does the service is then up to the branch: assigned for the
        // client when Auto-Assign Staff is on, else by the front desk.
        if (! BookingPolicy::clientMayChooseTherapist($branch)) {
            unset($payload['requested_therapist_uuid']);
        }

        return DB::transaction(function () use ($user, $branch, $payload, $home, $policy) {
            // One booking at a time per branch, so two clients can't both
            // take the last therapist for the same slot.
            SpaBranch::whereKey($branch->id)->lockForUpdate()->first();

            $client = $this->clientService->findOrCreateForUser($branch->spa_business_id, $user, $payload['client']);

            // The account remembers the gender given, so the app asks once.
            $gender = ClientProfile::gender($payload['client']['gender'] ?? null);
            if ($gender !== null && ClientProfile::gender($user->gender) === null) {
                $user->forceFill(['gender' => $gender])->save();
            }

            $appointment = $this->appointmentRepository->create([
                'spa_branch_id' => $branch->id,
                'client_id' => $client->id,
                'appointment_number' => $this->appointmentRepository->generateAppointmentNumber(),
                'appointment_date' => $payload['appointment_date'],
                'appointment_time' => $payload['appointment_time'],
                'appointment_type' => 'Reservation',
                'source' => 'Mobile',
                'status' => Appointment::STATUS_SCHEDULED,
                'remarks' => $payload['remarks'] ?? null,
            ] + ($home ? HomeServiceRules::attributes($payload, $home) : []) + $this->startAttributes($payload, (bool) $home));

            $serviceItems = [];

            foreach (array_values($payload['services'] ?? []) as $index => $item) {
                $serviceItems[] = $this->addServiceInternal(
                    $appointment,
                    $branch->id,
                    ['service_variant_uuid' => $item['service_variant_uuid']],
                    null,
                    $index + 1,
                );
            }

            if (! empty($payload['package_uuid'])) {
                $appointmentPackage = $this->addPackageInternal($appointment, $branch->spa_business_id, $payload['package_uuid'], 1);
                $serviceItems = array_merge(
                    $serviceItems,
                    $appointment->services()->where('source_appointment_package_id', $appointmentPackage->id)->get()->all(),
                );
            }

            // A voucher from the client's wallet for this visit: checked now, so
            // it can't come to nothing at the bill, and held for this booking.
            if ((! empty($payload['voucher_uuid']) || ! empty($payload['first_visit_program_uuid'])) && Appointment::holdsVouchers()) {
                $voucher = $this->voucherForBooking($client, $payload['voucher_uuid'] ?? null, $serviceItems, $payload['first_visit_program_uuid'] ?? null, $branch->id);
                Appointment::whereKey($appointment->id)->update(['client_voucher_id' => $voucher->id]);
            }

            $warnings = [];

            // Every service on a home visit must be one the owner marked for
            // home service (a stale menu, or a direct call). Rolls back.
            if ($home && ($blocked = HomeServiceRules::ineligibleServices($appointment))) {
                abort(422, HomeServiceRules::ineligibleMessage($blocked));
            }

            // Now that the services are on, the visit's real length is known:
            // it must end before closing and leave a
            // therapist free for it (BookingSlotService — the same check the
            // app's time chips were built from). abort() rolls this back.
            $appointment->load('services.serviceVariant');

            // The branch's "service time per booking" limit. A hard stop for
            // a client booking alone; the front desk is only warned about it.
            $serviceTimeProblem = BookingPolicy::serviceTimeProblem(
                BookingPolicy::for($branch),
                $this->availabilityService->estimatedDurationMinutes($appointment),
                (int) $appointment->services->map(fn ($s) => (int) ($s->serviceVariant?->duration_minutes ?? 0) * max(1, (int) ($s->quantity ?? 1)))->max(),
                $appointment->services->contains(fn ($s) => $s->source_appointment_package_id !== null),
            );
            abort_if($serviceTimeProblem !== null, 422, (string) $serviceTimeProblem);

            // A home visit has to be long enough to be worth the trip — again
            // a hard stop only here; the front desk is warned.
            $homeMinimumProblem = $home ? HomeServiceRules::minimumProblem(
                BookingPolicy::for($branch),
                $this->availabilityService->estimatedDurationMinutes($appointment),
            ) : null;
            abort_if($homeMinimumProblem !== null, 422, (string) $homeMinimumProblem);
            $requestedStaff = ! empty($payload['requested_therapist_uuid'])
                ? $this->resolveStaffForBranch($payload['requested_therapist_uuid'], $branch->id)
                : null;
            $slotProblem = app(BookingSlotService::class)->bookingProblem(
                $branch,
                $payload['appointment_date'],
                substr($payload['appointment_time'], 0, 5),
                max($this->availabilityService->estimatedDurationMinutes($appointment), 30),
                $requestedStaff,
                $appointment->id,
                (bool) $home,
            );
            abort_if($slotProblem !== null, 422, (string) $slotProblem);

            if (! empty($payload['requested_therapist_uuid'])) {
                // Unlike the front-desk path, a client's requested therapist
                // is a hard gate rather than a warning: the app filtered the
                // picker by this exact pair of checks (see
                // SpaBranchService::publicTherapistAvailability), so anything
                // reaching here is a stale list or a direct call, and silently
                // booking someone who isn't working would only surface as a
                // problem on the day. A front officer can renegotiate a shift;
                // a client on a phone can't, so they get told now.
                $this->assertRequestedTherapistIsFree(
                    $appointment,
                    $branch->id,
                    $payload['requested_therapist_uuid'],
                    $payload['appointment_date'],
                    $payload['appointment_time'],
                );
                $this->rememberRequestedTherapist($appointment, $branch->id, $payload['requested_therapist_uuid']);

                foreach ($serviceItems as $service) {
                    $this->applyRequestedTherapist($appointment, $branch->id, $service, $payload['requested_therapist_uuid'], $warnings);
                }
            }

            // No therapist asked for ("any"): nobody is picked here. The booking
            // still takes one of the free therapists in the slot count
            // (BookingSlotService), and the front desk assigns or reserves staff
            // for the client from the visit page — before they arrive or at
            // check-in — with the client's staff-gender preference in view.
            // (Picking someone at booking time used to hold whoever came first,
            // also staff with no shift that day.)

            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

            $appointment->setRelation('branch', $branch);
            $this->clientNotifier->appointment(
                $appointment,
                'Booking confirmed',
                "You're booked at {$branch->branch_name} on "
                    . Carbon::parse("{$payload['appointment_date']} {$payload['appointment_time']}")->format('D, M j \\a\\t g:i A')
                    . ". Your booking number is {$appointment->appointment_number}.",
            );

            $this->staffNotifier->appointment(
                $appointment,
                'New booking',
                trim("{$client->first_name} {$client->last_name}") . ' booked '
                    . Carbon::parse("{$payload['appointment_date']} {$payload['appointment_time']}")->format('D, M j \\a\\t g:i A')
                    . ". Booking no. {$appointment->appointment_number}.",
                'on_new_booking',
            );

            // The client's own view of the booking (total, duration, who's
            // assigned) — the app builds its confirmation from this.
            $resource = new ClientAppointmentResource(
                Appointment::with(ClientBookingService::RELATIONS)->findOrFail($appointment->id)
            );
            $warnings = array_values(array_unique($warnings));

            return $warnings ? $resource->additional(['warnings' => $warnings]) : $resource;
        });
    }

    // Client self-booking only. Runs the same schedule-then-booked checks,
    // in the same order, as the availability endpoint the app's therapist
    // picker was filtered by, and abort()s the surrounding transaction the
    // way addServiceInternal() does for an unbookable service — so a
    // rejection leaves no half-built appointment behind.
    //
    // Called once per appointment rather than per service: every service on
    // an appointment shares its one appointment_date/appointment_time, so
    // the window being checked is the same for all of them. The duration is
    // read off the already-added services, which is why this runs after
    // they're attached.
    // What a booking keeps for App\Support\HomeVisitStart and StaffGender: a
    // start code when it is a home visit, and the staff gender asked for.
    // Nothing until the columns exist (Appointment::hasHomeStart).
    private function startAttributes(array $payload, bool $isHome): array
    {
        if (! Appointment::hasHomeStart()) {
            return [];
        }

        return [
            'home_start_code' => $isHome ? Appointment::newHomeStartCode() : null,
            'preferred_staff_gender' => StaffGender::clean($payload['preferred_staff_gender'] ?? null),
        ];
    }

    // Anything added to a home visit later has to be doable at home too.
    // abort() rolls the surrounding transaction back.
    private function assertHomeEligible(Appointment $appointment): void
    {
        if ($appointment->isHomeService() && ($blocked = HomeServiceRules::ineligibleServices($appointment))) {
            abort(422, HomeServiceRules::ineligibleMessage($blocked));
        }
    }

    private function assertRequestedTherapistIsFree(
        Appointment $appointment,
        int $branchId,
        string $staffUuid,
        string $date,
        string $time,
    ): void {
        $staff = $this->resolveStaffForBranch($staffUuid, $branchId);

        // Left to applyRequestedTherapist's existing warning: an unknown or
        // inactive therapist isn't a scheduling conflict, and the booking is
        // still perfectly servable by whoever the branch assigns instead.
        if (! $staff) {
            return;
        }

        $appointment->load('services.serviceVariant.service');

        // A home visit needs someone the owner has marked for home service.
        if ($appointment->isHomeService() && ($refusal = HomeServiceRules::staffRefusal($staff))) {
            abort(422, $refusal);
        }

        // They have to be able to perform what is booked (a client can't be
        // given someone the owner has not qualified for it).
        foreach ($appointment->services as $item) {
            if (! \App\Support\ServiceKind::needsTherapist($item->kind())) {
                continue;
            }
            $serviceId = $item->serviceVariant?->service_id;
            if ($serviceId && ! $this->availabilityService->isStaffQualified($staff->id, $serviceId)['ok']) {
                abort(422, trim("{$staff->first_name} {$staff->last_name}") . ' does not perform ' . ($item->serviceVariant->service->name ?? 'this service') . '. Please pick another therapist.');
            }
        }
        // Same 30-minute floor busyWindowsForBranch() applies: an
        // appointment whose services carry no duration would otherwise
        // collapse to a zero-width window that can never overlap anything.
        $duration = max($this->availabilityService->estimatedDurationMinutes($appointment), 30);

        $schedule = $this->availabilityService->staffMatchesSchedule($staff->id, $date, $time, $duration);
        if (! $schedule['ok']) {
            abort(422, $schedule['reason']);
        }

        $booked = $this->availabilityService->isStaffAvailable($staff->id, $date, $time, $duration);
        if (! $booked['ok']) {
            abort(422, $booked['reason']);
        }
    }

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

            if ($stale = self::staleRefusal($appointment)) {
                return $stale;
            }
            // Nobody joins the line before the branch opens (its hours, Branch Settings → Operations).
            if ($closed = $this->closedNowRefusal($appointment->spa_branch_id, 'nobody can be checked in yet')) {
                return $closed;
            }
            // Here today for a later day's booking: it becomes today's, from now.
            if ($now && $appointment->status === Appointment::STATUS_SCHEDULED
                && $appointment->appointment_date->toDateString() > now()->toDateString()) {
                $from = $appointment->appointment_date->format('M j') . ' ' . Carbon::parse($appointment->appointment_time)->format('g:i A');
                $appointment->update([
                    'appointment_date' => now()->toDateString(),
                    'appointment_time' => now()->format('H:i'),
                    'remarks' => trim(($appointment->remarks ? $appointment->remarks . ' · ' : '') . "Came early — moved from {$from} and checked in now by {$user->displayName()}"),
                ]);
                // Whether it still fits today's hours is asked below, with every check-in.
            }
            // A suspended branch winds down: checking someone in starts something new.
            if ($suspended = Suspension::refusal($appointment->branch)) {
                return $suspended;
            }

            if (! $appointment->canTransitionTo(Appointment::STATUS_CHECKED_IN)) {
                return response()->json(['message' => 'Only a scheduled appointment can be checked in.'], 422);
            }
            // Only on the day of the booking: a client who comes on another day is
            // booked for that day instead (move the booking, then check them in).
            if ($appointment->appointment_date->toDateString() > now()->toDateString()) {
                return response()->json(['message' => 'This booking is for ' . $appointment->appointment_date->format('D, M j')
                    . '. To serve them today, use "Check in now" (they join the back of the line), or move the booking.'], 422);
            }
            // One visit at a time: not while another visit of theirs is in the queue or on the floor.
            $here = $appointment->client_id ? $this->visitHereNow($appointment->client_id) : null;
            if ($here && $here->id !== $appointment->id) {
                return response()->json(['message' => 'This client is already '
                    . ($here->status === Appointment::STATUS_IN_SERVICE ? 'being served' : 'in the queue')
                    . ' on another visit. Finish or cancel that one first.'], 422);
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
            $client = trim(($appointment->client?->first_name ?? '') . ' ' . ($appointment->client?->last_name ?? '')) ?: 'This client';
            // Moved to today just now: plainly the back of the line.
            if ($now) {
                $queuedAt = now();
            } elseif ($appointment->appointment_type !== 'Walk-in' && $appointment->branch) {
                $bookedStart = Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . $appointment->appointment_time);
                $grace = (int) (BookingPolicy::for($appointment->branch)['reservation_grace_minutes'] ?? ReservationHold::DEFAULT_GRACE_MINUTES);
                $booked = $bookedStart->format('g:i A');
                $arrival = ReservationHold::arrival($bookedStart, $grace, now());

                if ($arrival === 'early') {
                    if ($conflict = $this->availabilityService->aheadOfBooking($appointment, now())) {
                        $place = 'booked';
                        $note = $conflict;
                    } elseif (! in_array($place, ['now', 'booked'], true)) {
                        self::askCheckIn('early', "{$client} is early — booked for {$booked}. Queue them now, or at their booked time?", [
                            ['key' => 'now', 'label' => 'Queue now', 'hint' => 'Ahead of everyone who comes in after ' . now()->format('g:i A') . '.'],
                            ['key' => 'booked', 'label' => "Queue at booking time ({$booked})", 'hint' => "Takes their {$booked} place in line."],
                        ]);
                    }
                    $queuedAt = $place === 'now' ? now() : $bookedStart;
                } elseif ($arrival === 'late') {
                    if (! in_array($place, ['last', 'booked'], true)) {
                        $held = ReservationHold::endsAt($bookedStart, $grace)->format('g:i A');
                        self::askCheckIn('late', "{$client} is late — booked for {$booked} and held until {$held}. Where do they join the line?", [
                            ['key' => 'last', 'label' => 'Queue last', 'hint' => 'Behind everyone who is here now.'],
                            ['key' => 'booked', 'label' => "Keep their booked place ({$booked})", 'hint' => 'Ahead of everyone who came in after ' . $booked . '.'],
                        ]);
                    }
                    $queuedAt = $place === 'booked' ? $bookedStart : now();
                    $remarks[] = 'Arrived ' . now()->format('g:i A') . ', past the hold — '
                        . ($place === 'booked' ? "kept the {$booked} place" : 'queued last');
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

            $appointment->update(['status' => Appointment::STATUS_CHECKED_IN, 'check_in_at' => now()]
                + ($remarks ? ['remarks' => trim(($appointment->remarks ? $appointment->remarks . ' · ' : '') . implode(' · ', $remarks) . " (by {$user->displayName()})")] : []));
            $this->addToQueueInternal($appointment, $queuedAt);
            $gate->record($appointment, $overrides, $user);

            $queueNumber = $appointment->fresh('queue')->queue?->queue_number;
            $this->clientNotifier->appointment(
                $appointment,
                "You're checked in",
                "You're checked in at {$appointment->branch?->branch_name}."
                    . ($queueNumber ? " Your queue number is {$queueNumber}." : ''),
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
            if ($result instanceof \Illuminate\Http\JsonResponse && $result->getStatusCode() >= 400) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException($result);
            }

            return $result;
        });
    }

    // Stops a check-in to ask the desk (409 `check_in_choice`); thrown, not
    // returned, so the transaction rolls back whatever was already changed
    // (a booking "Check in now" had moved to today).
    private static function askCheckIn(string $kind, string $message, array $options): never
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
            'message' => $message,
            'check_in_choice' => ['kind' => $kind, 'message' => $message, 'options' => $options],
        ], 409));
    }

    // A visit from a day that is over and was never closed (App\Support\StaleVisit)
    // can no longer be run: no check-in, assigning, starting, completing or adding.
    // Marking it a no-show, cancelling it and moving it stay open, they settle it.
    // DayCloser closes it by itself. Null when the visit can be worked on.
    public static function staleRefusal(Appointment $appointment)
    {
        if (! in_array($appointment->status, [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CHECKED_IN, Appointment::STATUS_IN_SERVICE], true)
            || ! StaleVisit::isStale($appointment->appointment_date)) {
            return null;
        }

        return response()->json(['message' => 'This visit is from a previous day that was never closed, so it can no longer be changed here. It is closed automatically.'], 422);
    }

    public function markNoShow(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));

        if (! $appointment->canTransitionTo(Appointment::STATUS_NO_SHOW)) {
            return response()->json(['message' => 'Only a scheduled appointment that has not been checked in can be marked as no-show.'], 422);
        }

        $this->recordNoShow($appointment);

        return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
    }

    // A booking the client did not come to. Shared by the front desk (above) and
    // DayCloser, which marks the ones left "booked" when their day has ended, so
    // the two leave exactly the same behind: the booking a no-show, its services
    // (never done) and their staff and room off it, the client and the branch told.
    // The visit keeps the amount it was booked for (no totals recalculation: a
    // bulk update does not fire the line's events), so what was missed still reads.
    // $automatic: marked by DayCloser at the end of the day, not by the desk, so the client is always told.
    public function recordNoShow(Appointment $appointment, bool $automatic = false): void
    {
        DB::transaction(function () use ($appointment) {
            $appointment->update(['status' => Appointment::STATUS_NO_SHOW]);

            $serviceIds = $appointment->services()->pluck('id');
            $appointment->services()->where('status', 'Pending')->update(['status' => 'Cancelled']);
            TherapistAssignment::whereIn('appointment_service_id', $serviceIds)
                ->where('assignment_status', 'Assigned')
                ->update(['assignment_status' => 'Cancelled']);

            if ($appointment->queue) {
                $this->queueRepository->updateStatus($appointment->queue, 'Cancelled');
            }
        });

        // The no-show policy (Settings → Booking & Policies): say nothing,
        // warn the client, or — after so many — pause their booking from the
        // app (enforced in createClientAppointment; the desk needs an
        // override to reserve for them).
        $policy = $appointment->branch ? BookingPolicy::for($appointment->branch) : SpaBusinessSetting::DEFAULTS['booking_defaults'];
        $missed = $this->noShowCount($appointment->client_id);
        $action = FrontDeskPolicy::noShowAction($policy, $missed);
        if ($automatic && $action === 'none') {
            $this->clientNotifier->appointment(
                $appointment,
                'Booking marked as missed',
                "Your booking {$appointment->appointment_number} at {$appointment->branch?->branch_name} was marked as missed because you weren't checked in by the end of the day. If you did visit, please let the spa know.",
            );
        }
        if ($action !== 'none') {
            $spa = $appointment->branch?->branch_name;
            $this->clientNotifier->appointment(
                $appointment,
                $action === 'suspend' ? 'Booking through the app is paused' : 'You missed your booking',
                "You missed your booking {$appointment->appointment_number} at {$spa}."
                    . ($action === 'suspend'
                        ? " You have now missed {$missed} bookings, so booking through the app is paused. Please contact {$spa} to book."
                        : ($missed > 1 ? " You have now missed {$missed} bookings." : '')
                            . ($policy['no_show_policy'] === 'suspend' ? " After {$policy['no_show_suspend_after']} missed bookings, booking through the app will be paused." : '')),
            );
        }
        $this->staffNotifier->appointment(
            $appointment,
            'Client no-show',
            trim("{$appointment->client?->first_name} {$appointment->client?->last_name}") . " didn't arrive for booking {$appointment->appointment_number}.",
            'on_client_no_show',
        );

    }

    // A visit still in service when its day has ended, with nobody having done
    // anything about it: what was started is finished at its estimated end (when
    // it began plus its length, and the length of its add-ons) and not later than
    // the end of the day, so the duration availed is worked out from that; what was
    // never started is taken off. The visit is then ready to be billed.
    public function closeStaleVisit(Appointment $appointment): void
    {
        DB::transaction(function () use ($appointment) {
            $mains = $appointment->services()
                ->where('status', 'In Progress')
                ->when(Addon::ready(), fn ($q) => $q->whereNull('parent_appointment_service_id'))
                ->with('therapistAssignments', 'serviceVariant')
                ->lockForUpdate()
                ->get();
            foreach ($mains as $service) {
                $this->autoFinishService($service, $appointment);
            }

            $serviceIds = $appointment->services()->pluck('id');
            $waiting = $appointment->services()->where('status', 'Pending')->exists();
            if ($waiting) {
                $appointment->services()->where('status', 'Pending')->update(['status' => 'Cancelled']);
                TherapistAssignment::whereIn('appointment_service_id', $serviceIds)
                    ->where('assignment_status', 'Assigned')
                    ->update(['assignment_status' => 'Cancelled']);
                // Never done, so never charged: the bulk update did not fire the
                // line's events, hence the explicit recalculation.
                $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);
            }

            if ($appointment->queue && ! in_array($appointment->queue->queue_status, ['Completed', 'Cancelled'], true)
                && ! $appointment->services()->whereIn('status', ['Pending', 'In Progress'])->exists()) {
                $this->queueRepository->updateStatus($appointment->queue, 'Completed', ['completed_at' => now()]);
            }
        });
    }

    private function autoFinishService(AppointmentServiceItem $service, Appointment $appointment): void
    {
        $running = $service->therapistAssignments->where('assignment_status', 'In Progress');
        $startedAt = $running->pluck('started_at')->filter()->min();

        $minutes = (int) ($service->duration_minutes ?? $service->serviceVariant?->duration_minutes ?? 0);
        $minutes += (int) $service->liveAddons()->sum('duration_minutes');

        $dayEnd = Carbon::parse($appointment->appointment_date)->endOfDay();
        $at = $startedAt ? $startedAt->copy()->addMinutes($minutes) : $dayEnd;
        if ($at->greaterThan($dayEnd)) {
            $at = $dayEnd;
        }
        if ($startedAt && $at->lessThan($startedAt)) {
            $at = $startedAt->copy();
        }

        $this->startAddons($service, $at);
        foreach ($running as $assignment) {
            $assignment->update([
                'assignment_status' => 'Completed',
                'completed_at' => $at,
                'remarks' => trim(($assignment->remarks ? $assignment->remarks . ' · ' : '') . 'Finished automatically at its estimated end: nobody completed it before the day ended.'),
            ]);
        }
        $service->update(['status' => 'Completed']);
        app(CommissionCalculator::class)->freeze($service);
        $this->completeAddons($service, $at);
    }

    /** How many bookings this client has missed (no-shows). */
    // The bookings this client has missed since their last visit.
    public function noShowCount(int $clientId): int
    {
        return Appointment::where('client_id', $clientId)->missedSinceLastVisit()->count();
    }

    // How many bookings this signed-in client already has at the business on
    // that day (whichever branch): cancelled and missed ones don't count.
    // $exceptAppointmentId is the booking being moved.
    public function clientBookingsOn(User $user, int $businessId, string $date, ?int $exceptAppointmentId = null): int
    {
        return Appointment::whereHas('client', fn ($q) => $q->where('user_id', $user->id)->where('spa_business_id', $businessId))
            ->whereDate('appointment_date', $date)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
            ->when($exceptAppointmentId, fn ($q) => $q->where('id', '!=', $exceptAppointmentId))
            ->count();
    }

    // What a client is told when a day already holds the most bookings the
    // spa allows one client (Booking & Policies → bookings per client per day).
    public static function dayLimitMessage(int $limit, string $date): string
    {
        $day = Carbon::parse($date)->format('F j');

        return $limit === 1
            ? "You already have a booking on {$day}. This spa takes one booking per client a day — please pick another day."
            : "You already have {$limit} bookings on {$day}, the most this spa takes from one client in a day. Please pick another day.";
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

        if ($appointment->status !== Appointment::STATUS_SCHEDULED) {
            return response()->json(['message' => 'Only a client who has not checked in yet can be called. A client who is here is checked in: start their service.'], 422);
        }
        if ($appointment->appointment_date->format('Y-m-d') !== StaleVisit::today()) {
            return response()->json(['message' => 'Only a booking for today can be called.'], 422);
        }
        if ($stale = self::staleRefusal($appointment)) {
            return $stale;
        }

        // Calling is an alert in the client's app; a client without the app
        // has nothing to be alerted on, and is called by phone.
        if (! $appointment->client?->usesApp()) {
            return response()->json(['message' => 'Only clients who use the Servora app can be called. Phone them instead.'], 422);
        }

        if (! \Illuminate\Support\Facades\Cache::add("appointment-called:{$appointment->id}", true, now()->addMinutes(2))) {
            return response()->json(['message' => 'This client was called a moment ago. Give them a couple of minutes.'], 422);
        }

        $this->clientNotifier->appointment(
            $appointment,
            ClientNotificationLink::QUEUE_CALL_TITLE,
            "Please come to {$appointment->branch?->branch_name} and check in at the front desk for booking {$appointment->appointment_number}. Tap to see the queue.",
        );

        return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
    }

    // Moves a waiting or called client down one place in the line (see below).
    public function skipQueue(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        $queue = $appointment->queue;

        if (! $queue || ! in_array($queue->queue_status, ['Waiting', 'Called'], true)) {
            return response()->json(['message' => 'Only a waiting or called client can be skipped.'], 422);
        }
        // Someone whose service has begun (or is done) is being served: not skipped.
        if ($appointment->services()->whereIn('status', ['In Progress', 'Completed'])->exists()) {
            return response()->json(['message' => 'A service for this client has already started, so they can\'t be skipped.'], 422);
        }

        // Skipping moves them down one place: they swap with whoever is right behind them,
        // so ticket 8 skipped goes after ticket 9. (Before the queued_at column exists, the
        // old behaviour: they step out of the line until put back.)
        if (! Queue::ready()) {
            $this->queueRepository->updateStatus($queue, 'Skipped');

            return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
        }

        $walkInsFirst = $appointment->branch ? (bool) BookingPolicy::for($appointment->branch)['walk_ins_first_priority'] : false;
        $next = $this->queueRepository->nextBehind($queue->loadMissing('appointment'), $walkInsFirst);
        if (! $next) {
            return response()->json(['message' => 'No one is behind this client, so they are already last in line.'], 422);
        }

        $this->queueRepository->swapPlaces($queue, $next);

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

        if (! $queue || ! in_array($queue->queue_status, ['Called', 'Skipped'], true)) {
            return response()->json(['message' => 'Only a called or skipped client can be recalled to the queue.'], 422);
        }

        $this->queueRepository->updateStatus($queue, 'Waiting', ['called_at' => null]);

        return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
    }

    // Blocked once any service has started or finished (rule 4: cancellable
    // pre-start only, including while waiting/queued). Cascades cancel to
    // every still-pending service, their still-assigned therapists, and the
    // queue ticket — never deletes any of them, preserving history.
    public function cancelAppointment(User $user, string $uuid, ?string $reason = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($user, $branchIds, $uuid, $reason) {
            $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $branchIds);

            // Inside the cancellation window it is a late change: refused
            // unless overridden with a reason (PolicyGate). A booking still
            // only scheduled is what the window is about.
            $gate = app(PolicyGate::class);
            $overrides = $this->lateChangeOverrides($gate, $user, $appointment, 'cancel');
            if (! is_array($overrides)) {
                return $overrides;
            }

            $error = $this->performCancel($appointment, $reason);
            if ($error) {
                return $error;
            }
            $gate->record($appointment, $overrides, $user);

            $this->clientNotifier->appointment(
                $appointment,
                'Booking cancelled',
                "{$appointment->branch?->branch_name} has cancelled your booking {$appointment->appointment_number}."
                    . ($reason ? ' Reason: ' . rtrim($reason, '. ') . '.' : '')
                    . ' You can book again anytime in the app.',
            );

            return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
        });
    }

    // The cancel rules and cascade, shared by the front desk (above) and a
    // client cancelling their own booking (ClientBookingService) so the two
    // can never disagree about what "cancelled" leaves behind. Returns an
    // error response, or null once the appointment is cancelled. Must run
    // inside the caller's transaction.
    public function performCancel(Appointment $appointment, ?string $reason = null)
    {
        if (! $appointment->canTransitionTo(Appointment::STATUS_CANCELLED)) {
            return response()->json(['message' => 'This appointment can no longer be cancelled.'], 422);
        }

        $blocked = $appointment->services()->whereIn('status', ['In Progress', 'Completed'])->exists();
        if ($blocked) {
            return response()->json(['message' => 'Cannot cancel an appointment with a service already in progress or completed.'], 422);
        }

        $serviceIds = $appointment->services()->pluck('id');

        // Bulk query-builder update, not individual model saves — doesn't
        // fire AppointmentServiceItem's model events, so the totals
        // recalculation below can't be skipped in favor of relying on
        // that safety net alone (see AppointmentServiceItem::booted()).
        $appointment->services()->where('status', 'Pending')->update(['status' => 'Cancelled']);

        TherapistAssignment::whereIn('appointment_service_id', $serviceIds)
            ->where('assignment_status', 'Assigned')
            ->update(['assignment_status' => 'Cancelled']);

        if ($appointment->queue) {
            $this->queueRepository->updateStatus($appointment->queue, 'Cancelled');
        }

        $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

        $appointment->update([
            'status' => Appointment::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return null;
    }

    // Not a status transition — appointment_date/time simply change, so
    // this is gated by an explicit allow-list rather than canTransitionTo().
    // Only Scheduled/Checked-In are allowed: In Service is blocked outright
    // (rule: reschedule is blocked once in-service, with no override — the
    // in-progress/completed guard cancelAppointment() already enforces
    // means cancel-then-reschedule is never actually reachable once
    // in_service, so this simply refuses rather than offering a dead-end
    // "cancel first" path), and Completed/Cancelled/No Show are terminal.
    //
    // Does not re-validate existing therapist/room assignments against the
    // new time — reservations were never time-blocked in the first place
    // (see assignTherapistInternal()/assignRoomInternal()), so moving the
    // appointment doesn't need to re-run a check that no longer exists.
    public function rescheduleAppointment(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($user, $branchIds, $uuid, $payload) {
            $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $branchIds);

            // Moving it late is a late change, and the new time has to fit
            // the notice and days-ahead rules like any reservation.
            $gate = app(PolicyGate::class);
            $overrides = $this->lateChangeOverrides($gate, $user, $appointment, 'reschedule', $payload);
            if (! is_array($overrides)) {
                return $overrides;
            }

            $error = $this->performReschedule($appointment, $payload);
            if ($error) {
                return $error;
            }
            $gate->record($appointment, $overrides, $user);

            $this->clientNotifier->appointment(
                $appointment,
                'Booking rescheduled',
                "{$appointment->branch?->branch_name} has moved your booking {$appointment->appointment_number} to "
                    . Carbon::parse("{$payload['appointment_date']} {$payload['appointment_time']}")->format('D, M j \\a\\t g:i A') . '.',
            );

            return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
        });
    }

    // The cancellation-window rule (and, for a move, the booking window of the
    // new time) for the front desk — a refusal to return, or what to keep.
    private function lateChangeOverrides(PolicyGate $gate, User $user, Appointment $appointment, string $action, array $payload = [])
    {
        if (! $appointment->branch || $appointment->status !== Appointment::STATUS_SCHEDULED) {
            return [];
        }

        $policy = BookingPolicy::for($appointment->branch);
        $now = BookingPolicy::now();
        $startsAt = Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . $appointment->appointment_time, 'Asia/Manila');
        $problems = FrontDeskPolicy::change($policy, $startsAt, $now, $action);
        if ($action === 'reschedule' && ! empty($payload['appointment_date']) && ! empty($payload['appointment_time'])) {
            $problems = array_merge($problems, FrontDeskPolicy::window($policy, Carbon::parse("{$payload['appointment_date']} {$payload['appointment_time']}", 'Asia/Manila'), $now));
        }

        return $gate->check($user, $problems);
    }

    // How long the services in a booking request add up to (null when none
    // are picked yet) — lets the closure check catch a visit that starts
    // before a closure but would run into it.
    // The longest single service in a request (a package is not one).
    private function requestedLongestMinutes(array $payload): ?int
    {
        $items = collect($payload['services'] ?? []);
        if ($items->isEmpty()) {
            return null;
        }

        $minutes = ServiceVariant::whereIn('uuid', $items->pluck('service_variant_uuid'))->pluck('duration_minutes', 'uuid');

        return (int) $items->map(fn ($item) => (int) ($minutes[$item['service_variant_uuid']] ?? 0) * max(1, (int) ($item['quantity'] ?? 1)))->max();
    }

    private function requestedMinutes(array $payload): ?int
    {
        $items = collect($payload['services'] ?? []);
        $package = ! empty($payload['package_uuid']) ? (int) Package::where('uuid', $payload['package_uuid'])->value('duration_minutes') : 0;
        if ($items->isEmpty() && $package <= 0) {
            return null;
        }

        $minutes = ServiceVariant::whereIn('uuid', $items->pluck('service_variant_uuid'))->pluck('duration_minutes', 'uuid');
        $total = $package + $items->sum(fn ($item) => (int) ($minutes[$item['service_variant_uuid']] ?? 0) * max(1, (int) ($item['quantity'] ?? 1)));

        return $total > 0 ? $total : null;
    }

    // The reschedule rules, shared by the front desk (above) and a client
    // moving their own booking (ClientBookingService, which adds its own
    // stricter checks first). Returns an error response, or null once the
    // appointment has moved. Must run inside the caller's transaction.
    public function performReschedule(Appointment $appointment, array $payload)
    {
        if (! in_array($appointment->status, [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CHECKED_IN], true)) {
            return response()->json(['message' => 'Only a scheduled or checked-in appointment can be rescheduled.'], 422);
        }

        $scheduleCheck = $this->availabilityService->branchIsOpen(
            $appointment->spa_branch_id, $payload['appointment_date'], $payload['appointment_time'],
            fn () => max($this->availabilityService->estimatedDurationMinutes($appointment), 30),
        );
        if (! $scheduleCheck['ok']) {
            return response()->json(['message' => $scheduleCheck['reason']], 422);
        }

        // A reservation (a walk-in moved to a later day becomes one): the
        // spa's bookings-per-hour limit and the 5-minute marks. Its own
        // place is not counted, so moving inside its hour is fine.
        $staysWalkIn = $appointment->appointment_type === 'Walk-in' && $payload['appointment_date'] <= now()->toDateString();
        if (! $staysWalkIn) {
            SpaBranch::whereKey($appointment->spa_branch_id)->lockForUpdate()->first();
            $hourProblem = HourlyCapacity::problemFor(
                SpaBranch::findOrFail($appointment->spa_branch_id),
                $payload['appointment_date'], $payload['appointment_time'], $appointment->id,
            );
            if ($hourProblem) {
                return response()->json(['message' => $hourProblem], 422);
            }
        }

        // Moving a checked-in appointment to a new date/time means the
        // client hasn't actually checked in for that new slot yet — revert
        // to Scheduled and clear check_in_at, and void the queue ticket
        // check-in created (same call cancelAppointment() already uses to
        // void one), so it doesn't linger in the old date's queue.
        $wasCheckedIn = $appointment->status === Appointment::STATUS_CHECKED_IN;

        if ($wasCheckedIn && $appointment->queue) {
            $this->queueRepository->updateStatus($appointment->queue, 'Cancelled');
        }

        // A walk-in is same-day by definition ("this client walked in
        // right now") — pushed out to a future date, it's no longer that,
        // it's something booked ahead of time, i.e. a Reservation.
        // Same-day reschedules (just a different time) stay a walk-in.
        $becomesReservation = $appointment->appointment_type === 'Walk-in'
            && $payload['appointment_date'] > now()->toDateString();

        $appointment->update([
            'appointment_date' => $payload['appointment_date'],
            'appointment_time' => $payload['appointment_time'],
            'remarks' => $payload['reason'] ?? $appointment->remarks,
            'status' => $wasCheckedIn ? Appointment::STATUS_SCHEDULED : $appointment->status,
            'check_in_at' => $wasCheckedIn ? null : $appointment->check_in_at,
            'appointment_type' => $becomesReservation ? 'Reservation' : $appointment->appointment_type,
        ]);

        return null;
    }

    // ── Service / package selection ─────────────────────────────────────

    // Same code path for the initial post-check-in service selection and
    // for "add an additional service to an active appointment" (rule 11) —
    // there is nothing appointment-state-specific about adding a line item
    // beyond not being cancelled/no-show, so both are exposed as the same
    // controller action under two route names.
    public function addService(User $user, string $appointmentUuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentUuid, $payload) {
            $appointment = $this->appointmentRepository->findByUuidForBranches($appointmentUuid, $branchIds);

            if ($stale = self::staleRefusal($appointment)) {
                return $stale;
            }

            if (in_array($appointment->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW], true)) {
                return response()->json(['message' => 'Cannot add services to a cancelled or no-show appointment.'], 422);
            }

            // Every service done is no reason to stop: the client may want
            // more before paying. Once the bill is made, the bill is what's left.
            if ($refusal = $this->addingAfterBillRefusal($appointment, 'services')) {
                return $refusal;
            }

            $nextSort = (int) ($appointment->services()->max('sort_order') ?? 0) + 1;
            $this->addServiceInternal($appointment, $appointment->spa_branch_id, $payload, null, $nextSort);
            $this->assertHomeEligible($appointment);
            // Nothing added keeps a visit going past closing time.
            if ($late = $this->availabilityService->finishesPastClosing($appointment)) {
                abort(422, $late);
            }
            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);
            $this->reopenForMore($appointment);

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointmentUuid));
        });
    }

    // Adding to a visit whose services were all done: refused only once its
    // bill exists (paying is what's left then). Null when it may be added to.
    private function addingAfterBillRefusal(Appointment $appointment, string $what)
    {
        if ($appointment->allServicesResolved() && $this->billingRepository->findForAppointment($appointment->id)) {
            return response()->json(['message' => "This visit already has a bill — take the payment, then book the extra {$what} as a new visit."], 422);
        }

        return null;
    }

    // Something new to do on a visit whose ticket was finished (every service
    // had been done): the client is still here, being served — the ticket
    // goes back to Serving so the new service can be started from the floor.
    private function reopenForMore(Appointment $appointment): void
    {
        $queue = $appointment->fresh('queue')->queue;
        if ($queue && $queue->queue_status === 'Completed') {
            $this->queueRepository->updateStatus($queue, 'Serving', ['completed_at' => null]);
        }
    }

    // Only a service that hasn't started can be removed — once In Progress
    // or Completed it's part of the record (rule 17: preserve completed
    // service history). Status flip only, never deleted.
    public function removeService(User $user, string $appointmentServiceUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentServiceUuid) {
            $service = $this->appointmentServiceRepository->findByUuidForBranches($appointmentServiceUuid, $branchIds);

            if ($service->status !== 'Pending') {
                return response()->json(['message' => 'Only a service that has not started can be removed.'], 422);
            }
            // A package is sold whole: what came with it can't be taken off one by one.
            if ($service->source_appointment_package_id) {
                return response()->json(['message' => ($service->displayName() ?? 'This service') . ' comes with the package and can\'t be removed on its own.'], 422);
            }

            $this->appointmentServiceRepository->cancel($service);
            // Its add-ons were waiting on it: they go with it.
            foreach ($service->liveAddons() as $addon) {
                $this->appointmentServiceRepository->cancel($addon);
            }

            $appointment = $service->appointment;
            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    // Puts an add-on (App\Models\Addon) on one of the visit's services: hot
    // stones on the massage. Only one that is available for that service —
    // its category, and the service itself when the owner narrowed it
    // (App\Support\ServiceAddon) — while the service is waiting or in
    // progress and nothing has been billed. It becomes a charged line of its
    // own under its main service, with its own copy of the name, minutes and
    // price (editing the add-on later never changes this visit), and it
    // rides with it — the main's therapist(s) and room, started with it (at
    // once, if the main is already running) and completed with it — so the
    // bill and the commission count it like any other service.
    public function addAddon(User $user, string $appointmentServiceUuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }
        if (! Addon::ready()) {
            return response()->json(['message' => 'Add-ons are not set up on this server yet.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($business, $branchIds, $appointmentServiceUuid, $payload) {
            $main = AppointmentServiceItem::whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $appointmentServiceUuid)
                ->lockForUpdate()
                ->with('serviceVariant.service')
                ->firstOrFail();
            $appointment = Appointment::whereKey($main->appointment_id)->lockForUpdate()->firstOrFail();

            if ($stale = self::staleRefusal($appointment)) {
                return $stale;
            }

            $addon = Addon::where('uuid', $payload['addon_uuid'])
                ->where('spa_business_id', $business->id)
                ->with('services:id')
                ->first();
            if (! $addon) {
                return response()->json(['message' => 'This add-on is no longer on the menu.'], 422);
            }

            // Where the service sits on the menu today decides what goes with
            // it; a service since taken off the menu is judged by what the
            // line kept.
            $mainService = $main->serviceVariant?->service;
            $problem = ServiceAddon::problem(
                [
                    'status' => $main->status,
                    'is_addon' => $main->isAddon(),
                    'service_id' => $main->serviceVariant?->service_id,
                    'category' => $mainService?->category ?? $main->category(),
                    'name' => $main->displayName(),
                ],
                $addon->rule(),
                $main->liveAddons()->pluck('addon_id')->filter()->all(),
                $appointment->status,
                $this->billingRepository->findForAppointment($appointment->id) !== null,
            );
            if ($problem) {
                return response()->json(['message' => $problem], 422);
            }

            $price = (float) $addon->price;
            $line = $this->appointmentServiceRepository->create([
                'appointment_id' => $appointment->id,
                'parent_appointment_service_id' => $main->id,
                'addon_id' => $addon->id,
                // Its own copy (App\Support\History).
                'service_name' => $addon->name,
                'duration_minutes' => (int) $addon->duration_minutes,
                'service_type' => ServiceKind::TIME,
                'quantity' => 1,
                'sort_order' => (int) ($appointment->services()->max('sort_order') ?? 0) + 1,
                'unit_price' => $price,
                'discount_amount' => 0,
                'subtotal' => $price,
                'points_earned' => 0,
            ]);
            if ($main->status === 'In Progress') {
                $this->rideWith($line, $main, now());
            }
            // Nothing added keeps a visit going past closing time.
            if ($late = $this->availabilityService->finishesPastClosing($appointment)) {
                abort(422, $late);
            }
            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    // Starts a main service's add-ons with it — the ones still waiting.
    private function startAddons(AppointmentServiceItem $main, $now): void
    {
        foreach ($main->liveAddons()->where('status', 'Pending') as $addon) {
            $this->rideWith($addon, $main, $now);
        }
    }

    // An add-on has no therapist or room of its own: it takes its main
    // service's — whoever is performing the main right now, where they are —
    // and is in progress from this moment. On a service nobody performs (a
    // sauna) that is the room alone, so no commission is earned on it.
    private function rideWith(AppointmentServiceItem $addon, AppointmentServiceItem $main, $now): void
    {
        $running = TherapistAssignment::where('appointment_service_id', $main->id)
            ->where('assignment_status', 'In Progress')
            ->get();

        foreach ($running as $assignment) {
            TherapistAssignment::updateOrCreate(
                ['appointment_service_id' => $addon->id, 'staff_id' => $assignment->staff_id],
                [
                    'facility_id' => $assignment->facility_id,
                    'assignment_status' => 'In Progress',
                    'assigned_at' => $now,
                    'started_at' => $now,
                    'completed_at' => null,
                ]
            );
        }

        $addon->update(['status' => 'In Progress']);
    }

    // Completes a main service's add-ons with it, and fixes what each earned.
    private function completeAddons(AppointmentServiceItem $main, $now): void
    {
        foreach ($main->liveAddons()->where('status', 'In Progress') as $addon) {
            TherapistAssignment::where('appointment_service_id', $addon->id)
                ->where('assignment_status', 'In Progress')
                ->get()
                ->each(fn (TherapistAssignment $a) => $a->update(['assignment_status' => 'Completed', 'completed_at' => $now]));
            $addon->update(['status' => 'Completed']);
            app(CommissionCalculator::class)->freeze($addon);
        }
    }

    // An add-on is never handled as a service of its own: it has no therapist,
    // room, Start or Complete — its main service's are its own.
    private function addonFollows(AppointmentServiceItem $service, string $what)
    {
        return $service->isAddon()
            ? response()->json(['message' => "An add-on {$what} with its main service."], 422)
            : null;
    }

    // Takes a service that has already started or finished off the visit —
    // added by mistake, the client changed their mind part-way, it had to be
    // stopped. Unlike removeService() above (not started yet, no questions
    // asked) this needs a reason, which is kept on the line with who did it,
    // and it is only allowed while nothing has been settled against it
    // (App\Support\ServiceVoidRules).
    //
    // What it does: the line and its therapist/room assignments are marked
    // Cancelled (never deleted) — so it is no longer charged, the therapist
    // earns no commission for it, and the therapist and room are free again.
    // A service that came with a package takes its share of the package
    // price with it (App\Support\PackageShare) and the rest of the package
    // stays; the last one takes the package itself. A main service takes its
    // add-ons with it; an add-on can be voided alone. An unpaid bill is
    // brought down to the new total.
    public function voidService(User $user, string $appointmentServiceUuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($user, $branchIds, $appointmentServiceUuid, $payload) {
            $service = $this->appointmentServiceRepository->findByUuidForBranches($appointmentServiceUuid, $branchIds);
            $appointment = Appointment::whereKey($service->appointment_id)
                ->lockForUpdate()
                ->with(['services.therapistAssignments', 'packages', 'queue'])
                ->firstOrFail();

            // Only a service that has not started can be taken off a visit
            // (removeService). One that has is part of the record: what the client
            // paid for it is handed back at checkout instead (a refund for that service).
            if (in_array($service->status, ['In Progress', 'Completed'], true)) {
                return response()->json(['message' => 'A service that has started cannot be cancelled. If the client paid for it, refund that service at checkout.'], 422);
            }

            $line = $appointment->services->firstWhere('id', $service->id) ?? $service;
            $package = $service->source_appointment_package_id
                ? $appointment->packages->firstWhere('id', $service->source_appointment_package_id)
                : null;

            // Its share of the package, by list price, among the package's
            // services still on the visit (what an extension added on top is
            // the line's own and goes with it anyway).
            $packageLines = $package
                ? $appointment->services->where('source_appointment_package_id', $package->id)->where('status', '!=', 'Cancelled')
                : collect();
            // Its add-ons go with it (App\Support\ServiceAddon).
            $addons = $appointment->services
                ->where('status', '!=', 'Cancelled')
                ->filter(fn ($s) => $s->parent_appointment_service_id !== null && (int) $s->parent_appointment_service_id === (int) $service->id);
            $going = $addons->pluck('id')->push($service->id)->all();

            // …and the ones that came with the package take their own shares
            // of its price too.
            $weights = $packageLines->mapWithKeys(fn ($l) => [
                $l->id => max(0, (float) $l->unit_price * max(1, (int) $l->quantity) - (float) ($l->extra_charge ?? 0)),
            ])->all();
            $share = $package
                ? round(collect($going)->filter(fn ($id) => array_key_exists($id, $weights))->sum(fn ($id) => PackageShare::of((float) $package->subtotal, $weights, $id)), 2)
                : 0.0;
            $packageEnds = $package && $packageLines->whereNotIn('id', $going)->isEmpty();

            // What would still be charged afterwards (the home-service fee
            // alone is not a visit).
            $left = $appointment->services->where('status', '!=', 'Cancelled')->whereNotIn('id', $going);
            $leftCharge = $left->whereNull('source_appointment_package_id')->sum(fn ($s) => (float) $s->subtotal - (float) $s->discount_amount)
                + $left->whereNotNull('source_appointment_package_id')->sum(fn ($s) => (float) ($s->extra_charge ?? 0))
                + $appointment->packages->where('status', '!=', 'Cancelled')->sum(fn ($p) => (float) $p->subtotal - (float) $p->discount_amount - ($p->id === $package?->id ? $share : 0));

            $bill = $this->billingRepository->findForAppointment($appointment->id);
            $problem = ServiceVoidRules::problem(
                $service->status,
                $appointment->status,
                $bill ? [
                    'status' => $bill->status,
                    'kept' => (float) $bill->payments->where('payment_status', 'Paid')->sum(fn ($p) => (float) $p->amount - (float) $p->refunded_amount),
                    'discount' => (float) $bill->discount_amount,
                    'credit' => (float) ($bill->credit_amount ?? 0),
                ] : null,
                $leftCharge <= 0,
            );
            if ($problem) {
                return response()->json(['message' => $problem], 422);
            }

            $by = $user->displayName();

            foreach ($line->therapistAssignments->whereIn('assignment_status', ['Assigned', 'In Progress', 'Completed']) as $assignment) {
                $assignment->update(['assignment_status' => 'Cancelled']);
            }
            $reason = $payload['reason'] . ($package
                ? ' — ₱' . number_format($share, 2) . ' off ' . ($package->displayName() ?? 'the package')
                : '');
            $line->update([
                'status' => 'Cancelled',
                'notes' => ServiceVoidRules::note($reason, $by, $line->notes),
            ]);
            foreach ($addons as $addon) {
                foreach ($addon->therapistAssignments->whereIn('assignment_status', ['Assigned', 'In Progress', 'Completed']) as $assignment) {
                    $assignment->update(['assignment_status' => 'Cancelled']);
                }
                $addon->update([
                    'status' => 'Cancelled',
                    'notes' => ServiceVoidRules::note($payload['reason'], $by, $addon->notes),
                ]);
            }
            $package?->update(
                ['subtotal' => round(max(0, (float) $package->subtotal - $share), 2)]
                + ($packageEnds ? ['status' => 'Cancelled'] : [])
            );

            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);
            $appointment->refresh();

            // The bill was made from the old total (no payment, no discount on
            // it — the rules above saw to that), so it simply follows.
            if ($bill) {
                $bill->update(['subtotal' => $appointment->total_amount, 'amount' => $appointment->total_amount]);
            }

            // Where that leaves the visit.
            $live = $appointment->services()->where('status', '!=', 'Cancelled')->get();
            $served = $live->whereIn('status', ['In Progress', 'Completed'])->isNotEmpty();
            $unfinished = $live->whereIn('status', ['Pending', 'In Progress'])->isNotEmpty();
            $queue = $appointment->queue;

            if (! $served && ! $bill && $appointment->status === Appointment::STATUS_IN_SERVICE) {
                // Nothing has been served any more: the client is checked in
                // and waiting again, so the visit can carry on or be cancelled.
                $appointment->update(['status' => Appointment::STATUS_CHECKED_IN]);
                if ($queue && in_array($queue->queue_status, ['Serving', 'Completed'], true)) {
                    $this->queueRepository->updateStatus($queue, 'Waiting', ['served_at' => null, 'completed_at' => null]);
                }
            } elseif ($live->isNotEmpty() && ! $unfinished && $queue && $queue->queue_status !== 'Completed') {
                // What is left is all done: the ticket is finished, as when the
                // last service is completed.
                $this->queueRepository->updateStatus($queue, 'Completed', ['completed_at' => now()]);
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    // Explodes the package into one AppointmentServiceItem per
    // package_services line (x quantity), tagged back to the purchase
    // record — see AppointmentPackage/AppointmentServiceItem docblocks.
    // This is what gives packages the same multi-therapist/room path as a
    // standalone service.
    public function addPackage(User $user, string $appointmentUuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($business, $branchIds, $appointmentUuid, $payload) {
            $appointment = $this->appointmentRepository->findByUuidForBranches($appointmentUuid, $branchIds);

            if ($stale = self::staleRefusal($appointment)) {
                return $stale;
            }

            if (in_array($appointment->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW], true)) {
                return response()->json(['message' => 'Cannot add packages to a cancelled or no-show appointment.'], 422);
            }

            // Every service done is no reason to stop: the client may want
            // more before paying. Once the bill is made, the bill is what's left.
            if ($refusal = $this->addingAfterBillRefusal($appointment, 'packages')) {
                return $refusal;
            }

            $this->addPackageInternal($appointment, $business->id, $payload['package_uuid'], $payload['quantity'] ?? 1, openWork: true);
            $this->assertHomeEligible($appointment);
            // Nothing added keeps a visit going past closing time.
            if ($late = $this->availabilityService->finishesPastClosing($appointment)) {
                abort(422, $late);
            }

            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);
            $this->reopenForMore($appointment);

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointmentUuid));
        });
    }

    // ── Therapist / room assignment ─────────────────────────────────────

    // Every active therapist at this service's branch — the picker still
    // shows the whole roster rather than hiding anyone, but each row now
    // carries whether they're qualified for THIS service so the front desk
    // sees the reason up front instead of discovering it as a 422 from
    // assignTherapist(). Combined with today's attendance check-in and
    // their schedule for this exact appointment slot. Reuses
    // AttendanceStatusCalculator::resolve() (same status/shift-window logic
    // the Attendance pages already use) and
    // AppointmentAvailabilityService::staffMatchesSchedule()/isStaffQualified()
    // (the exact checks assignTherapistInternal() already gates on) rather
    // than reimplementing any of them.
    //
    // `pickable` is check-in AND qualification — deliberately the same pair
    // assignTherapistInternal() enforces. A therapist working outside their
    // scheduled shift is still pickable, just flagged
    // 'checked_in_off_schedule', since attendance (not schedule) is the
    // real-world fact that matters for "can they actually do this right
    // now"; qualification, by contrast, is a hard skill requirement that is
    // never bypassed.
    //
    // `qualified` is returned alongside `state` rather than folded into it:
    // the six states describe presence/schedule, and qualification is
    // orthogonal to all of them (an off-schedule therapist can be qualified,
    // a checked-in one can be unqualified).
    public function therapistOptions(User $user, string $appointmentServiceUuid)
    {
        $branchIds = $this->branchIds($user);

        $service = AppointmentServiceItem::whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
            ->where('uuid', $appointmentServiceUuid)
            ->with('serviceVariant', 'appointment', 'therapistAssignments')
            ->firstOrFail();

        $appointment = $service->appointment;
        $date = $appointment->appointment_date->format('Y-m-d');
        $time = $appointment->appointment_time;
        $duration = $service->lengthMinutes() ?? 30;
        $dayOfWeek = Carbon::parse($date)->format('l');

        // Qualification is tracked at the Service level, not per variant —
        // qualified for "Swedish Massage" covers every duration of it — so
        // this hops variant -> service, exactly as assignTherapistInternal()
        // does.
        $serviceId = $service->serviceVariant?->service_id;

        $therapists = Staff::where('spa_branch_id', $appointment->spa_branch_id)
            ->where('role', 'therapist')
            ->where('status', 'active')
            ->with('schedules')
            ->orderBy('first_name')
            ->get();

        $attendanceByStaffId = Attendance::whereIn('staff_id', $therapists->pluck('id'))
            ->where('attendance_date', \App\Support\BusinessDay::attendanceDate($appointment->spa_branch_id, $date))
            ->get()
            ->keyBy('staff_id');

        $branchSchedule = BranchSchedule::where('spa_branch_id', $appointment->spa_branch_id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        // The branch's turn order for this date, keyed by staff id. Shares one
        // calculator with the front-desk queue board so the two screens can
        // never disagree about whose turn it is.
        $rotationByStaffId = $this->therapistQueueCalculator
            ->forBranch($appointment->spa_branch_id, $date, $therapists)
            ->keyBy('staff_id');

        // Anyone already holding this service is out of the running for it —
        // the picker filters them out of the list, so naming one of them as
        // "next up" would point at a row that isn't on screen.
        $heldStaffIds = $service->therapistAssignments
            ->whereIn('assignment_status', ['Assigned', 'In Progress'])
            ->pluck('staff_id')
            ->filter()
            ->all();
        $alreadyOnThisServiceUuids = $therapists
            ->whereIn('id', $heldStaffIds)
            ->pluck('uuid')
            ->all();

        $appointment->loadMissing('branch.business.settings');
        $grace = AttendanceStatusCalculator::graceMinutesFor($appointment->branch?->business);

        // A home visit can only go to staff marked "Available for home service".
        $isHome = $appointment->isHomeService();

        $options = $therapists->map(function (Staff $staff) use ($attendanceByStaffId, $date, $time, $duration, $branchSchedule, $serviceId, $rotationByStaffId, $grace, $isHome) {
            $attendance = $attendanceByStaffId->get($staff->id);
            $resolved = $this->attendanceStatusCalculator->resolve($staff, $attendance, $date, $staff->schedules, $branchSchedule, graceMinutes: $grace);
            $scheduleCheck = $this->availabilityService->staffMatchesSchedule($staff->id, $date, $time, $duration);

            // No resolvable service (a variant that's since been deleted)
            // leaves everyone qualified rather than blocking the whole
            // picker — assignTherapistInternal() would surface the real
            // problem on submit.
            $qualified = $serviceId === null
                || $this->availabilityService->isStaffQualified($staff->id, $serviceId)['ok'];

            $homeOk = ! $isHome || $staff->takesHomeService();
            $checkedIn = (bool) $attendance?->check_in_at;
            $matchesWindow = $scheduleCheck['ok'];

            $state = match (true) {
                $checkedIn && $resolved['is_day_off'] => 'checked_in_day_off',
                $checkedIn && $matchesWindow => 'available',
                $checkedIn && ! $matchesWindow => 'checked_in_off_schedule',
                ! $checkedIn && $resolved['is_day_off'] => 'day_off',
                ! $checkedIn && $matchesWindow => 'not_checked_in',
                default => 'not_scheduled',
            };

            $rotation = $rotationByStaffId->get($staff->id);

            return [
                'uuid' => $staff->uuid,
                'name' => trim("{$staff->first_name} {$staff->last_name}"),
                // What they do, main one first (App\Support\StaffSpecialty).
                'specialties' => $staff->specialtyList(),
                // For the preferred-gender filter (App\Support\StaffGender).
                'gender' => $staff->gender,
                'state' => $state,
                'qualified' => $qualified,
                // False when this is a home visit and they aren't marked
                // "Available for home service" (always true for a visit in the spa).
                'home_service_ok' => $homeOk,
                // Turn order — see TherapistQueueCalculator. Null position
                // means they aren't on the floor (never timed in, or already
                // timed out), which is a different thing from being last.
                'queue_position' => $rotation['position'] ?? null,
                'in_queue' => (bool) ($rotation['in_queue'] ?? false),
                'rotation_status' => $rotation['rotation_status'] ?? 'free',
                'last_completed_at' => $rotation['last_completed_at']?->format('H:i'),
                // Set below, once the whole list exists — it's a property of
                // the list, not of any single therapist.
                'is_next_up' => false,
                // Checked in plus qualified — a real, in-person fact always
                // overrides a static schedule (day off included), but never
                // a missing skill; see assignTherapistInternal(), which
                // skips the schedule check entirely once checked in while
                // always running the qualification check, matching this
                // exactly.
                'pickable' => $checkedIn && $qualified && $homeOk,
                'checked_in_at' => $attendance?->check_in_at?->format('H:i'),
                'is_day_off' => $resolved['is_day_off'],
                'scheduled_start' => $resolved['scheduled_start'],
                'scheduled_end' => $resolved['scheduled_end'],
            ];
        })
            // Turn order, with everyone who can't take this booking pushed
            // below: on the floor first, then off it, and within each group
            // the unqualified last. Nobody is dropped — the front desk should
            // be able to see why someone isn't an option.
            ->sortBy([
                fn (array $a, array $b) => ($b['home_service_ok'] <=> $a['home_service_ok']),
                fn (array $a, array $b) => ($b['qualified'] <=> $a['qualified']),
                fn (array $a, array $b) => ($b['in_queue'] <=> $a['in_queue']),
                fn (array $a, array $b) => ($a['queue_position'] ?? PHP_INT_MAX) <=> ($b['queue_position'] ?? PHP_INT_MAX),
            ])
            ->values();

        // "Next up" for THIS booking, which is narrower than the branch-wide
        // rotation: the first therapist who is on the floor, not in the
        // middle of a service (one assigned to a booking that hasn't started
        // still counts — App\Support\RotationTurn), qualified for this
        // service, and not already assigned to it. A #1 who can't perform
        // this service isn't actually next here, and saying otherwise sends
        // the front desk down a dead end.
        $nextUpIndex = $options->search(fn (array $row) => RotationTurn::canTakeNext($row['in_queue'], $row['rotation_status'])
            && $row['qualified']
            && $row['home_service_ok']
            && ! in_array($row['uuid'], $alreadyOnThisServiceUuids, true));

        if ($nextUpIndex !== false) {
            $options = $options->replace([$nextUpIndex => array_merge($options[$nextUpIndex], ['is_next_up' => true])]);
        }

        return response()->json(['data' => $options->values()]);
    }

    public function assignTherapist(User $user, string $appointmentServiceUuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentServiceUuid, $payload) {
            $service = AppointmentServiceItem::whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $appointmentServiceUuid)
                ->lockForUpdate()
                ->with('serviceVariant', 'appointment')
                ->firstOrFail();

            if ($stale = self::staleRefusal($service->appointment)) {
                return $stale;
            }

            if (in_array($service->status, ['Cancelled', 'Completed'], true)) {
                return response()->json(['message' => 'Cannot assign staff to a cancelled or completed service.'], 422);
            }
            if ($refusal = $this->addonFollows($service, 'shares its staff and facility')) {
                return $refusal;
            }

            // Room-only reservation — a room can be locked in for a service
            // before a therapist is picked (assigned ahead of time, or right
            // when the client is up for service); staff can be claimed onto
            // the same row later via assignTherapistInternal().
            if (empty($payload['staff_uuid'])) {
                if (empty($payload['facility_uuid'])) {
                    return response()->json(['message' => 'Choose a staff member or a facility.'], 422);
                }

                $roomResult = $this->assignRoomOnlyInternal($service, $service->appointment, $payload['facility_uuid']);
                if (! $roomResult['ok']) {
                    return response()->json(['message' => $roomResult['reason']], 422);
                }

                return new AppointmentResource($this->appointmentRepository->findByUuid($service->appointment->uuid));
            }

            $staff = $this->resolveStaffForBranch($payload['staff_uuid'], $service->appointment->spa_branch_id);
            if (! $staff) {
                return response()->json(['message' => 'Staff member not found or inactive at this branch.'], 422);
            }

            $result = $this->assignTherapistInternal($service, $service->appointment, $staff);
            if (! $result['ok']) {
                return response()->json(['message' => $result['reason']], 422);
            }

            if (! empty($payload['facility_uuid'])) {
                $roomResult = $this->assignRoomInternal($result['assignment'], $service->appointment, $service, $payload['facility_uuid']);
                if (! $roomResult['ok']) {
                    return response()->json(['message' => $roomResult['reason']], 422);
                }
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($service->appointment->uuid));
        });
    }

    public function cancelAssignment(User $user, string $assignmentUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $assignmentUuid) {
            $assignment = TherapistAssignment::whereHas('appointmentService.appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $assignmentUuid)
                ->with('appointmentService.appointment')
                ->firstOrFail();

            if ($assignment->assignment_status === 'In Progress') {
                return response()->json(['message' => 'Cannot remove a staff member currently performing the service.'], 422);
            }

            // Closes the dangling-row case: an Assigned-but-never-started
            // row left over on a service that was already completed via a
            // different assignment (assignment_status alone wouldn't catch
            // this — it's still just 'Assigned').
            if (in_array($assignment->appointmentService->status, ['Completed', 'Cancelled'], true)) {
                return response()->json(['message' => 'This service is already completed — its assignments can no longer be changed.'], 422);
            }

            $this->therapistAssignmentRepository->cancel($assignment);

            return new AppointmentResource($this->appointmentRepository->findByUuid($assignment->appointmentService->appointment->uuid));
        });
    }

    // Targets a specific therapist_assignment row (a room is attached per
    // assignment, not per appointment or even per service — rule: don't
    // assume one room per appointment).
    public function assignRoom(User $user, string $assignmentUuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $assignmentUuid, $payload) {
            $assignment = TherapistAssignment::whereHas('appointmentService.appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $assignmentUuid)
                ->lockForUpdate()
                ->with('appointmentService.serviceVariant', 'appointmentService.appointment')
                ->firstOrFail();

            if (in_array($assignment->assignment_status, ['Completed', 'Cancelled'], true)) {
                return response()->json(['message' => 'Cannot assign a facility to a completed or cancelled assignment.'], 422);
            }

            $service = $assignment->appointmentService;
            $appointment = $service->appointment;

            if ($stale = self::staleRefusal($appointment)) {
                return $stale;
            }


            // Same dangling-row case as cancelAssignment() — this
            // assignment row is only 'Assigned', but its service already
            // finished via a different assignment.
            if (in_array($service->status, ['Completed', 'Cancelled'], true)) {
                return response()->json(['message' => 'This service is already completed — its assignments can no longer be changed.'], 422);
            }
            if ($refusal = $this->addonFollows($service, 'shares its staff and facility')) {
                return $refusal;
            }

            $result = $this->assignRoomInternal($assignment, $appointment, $service, $payload['facility_uuid']);
            if (! $result['ok']) {
                return response()->json(['message' => $result['reason']], 422);
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    // Takes the room off an assignment but keeps its therapist — the "×" on
    // the room chip. (cancelAssignment drops the whole row, therapist and
    // all.) A room-only row has nothing left once its room goes, so it is
    // cancelled instead.
    public function clearRoom(User $user, string $assignmentUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $assignmentUuid) {
            $assignment = TherapistAssignment::whereHas('appointmentService.appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $assignmentUuid)
                ->lockForUpdate()
                ->with('appointmentService.appointment')
                ->firstOrFail();

            $service = $assignment->appointmentService;

            if (! $assignment->facility_id) {
                return response()->json(['message' => 'This service has no facility to remove.'], 422);
            }
            if (in_array($assignment->assignment_status, ['Completed', 'Cancelled'], true)
                || in_array($service->status, ['Completed', 'Cancelled'], true)) {
                return response()->json(['message' => 'This service is already finished — its facility can no longer be changed.'], 422);
            }
            // The client is in the room: it can be changed (assignRoom), not
            // taken away, until the service is completed.
            if ($assignment->assignment_status === 'In Progress' || $service->status === 'In Progress') {
                return response()->json(['message' => 'This service is under way in that facility — it can be moved to another facility, but not left without one until it is completed.'], 422);
            }
            // The same checks as assigning one.
            if ($stale = self::staleRefusal($service->appointment)) {
                return $stale;
            }
            if ($refusal = $this->addonFollows($service, 'shares its staff and facility')) {
                return $refusal;
            }

            $assignment->update($assignment->staff_id
                ? ['facility_id' => null]
                : ['facility_id' => null, 'assignment_status' => 'Cancelled']);

            return new AppointmentResource($this->appointmentRepository->findByUuid($assignment->appointmentService->appointment->uuid));
        });
    }

    // ── Queue ────────────────────────────────────────────────────────────

    // Places an already-checked-in appointment on today's queue. Called from
    // checkIn() and from createAppointment()'s walk-in path — never exposed
    // directly, since every checked-in appointment is queued by the time
    // either of those returns. No-op if a queue row already exists so callers
    // don't need to guard against calling it twice.
    // The branch is closed right now (outside its hours, or a closed day —
    // App\Support\BusinessDay): why $what, and when it opens. Null while open.
    private function closedNowRefusal(int $branchId, string $what)
    {
        $week = \App\Support\BusinessDay::week($branchId);
        if (\App\Support\BusinessDay::isOpen($week, now())) {
            return null;
        }
        $opens = \App\Support\BusinessDay::opensNext($week, now());

        return response()->json(['message' => "The branch isn't open right now, so {$what}."
            . ($opens ? ' It opens ' . ($opens->isToday() ? 'at ' . $opens->format('g:i A') : $opens->format('D, M j \a\t g:i A')) . '.' : '')], 422);
    }

    // The client's visit that is here right now — checked in (in the queue)
    // or in service — on a day that isn't over (StaleVisit), if any.
    private function visitHereNow(int $clientId): ?Appointment
    {
        return Appointment::where('client_id', $clientId)
            ->whereIn('status', [Appointment::STATUS_CHECKED_IN, Appointment::STATUS_IN_SERVICE])
            ->whereDate('appointment_date', '>=', StaleVisit::today())
            ->with('queue')
            ->first();
    }

    // $queuedAt: its place in the line, when that isn't "now" (a reservation
    // arriving in time keeps its booked time — see checkIn()).
    private function addToQueueInternal(Appointment $appointment, ?CarbonInterface $queuedAt = null): void
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

    // ── Service execution ────────────────────────────────────────────────

    // Serves both "Start Early" and a normal on-time start — identical
    // backend contract either way (rule 8's explicit-choice requirement is
    // satisfied by the click itself; the frontend alone decides when to
    // surface an early-start affordance). Requires at least one Assigned
    // therapist first (rule 9) and re-validates every assigned
    // therapist/room in real time to catch drift from an earlier service
    // running long (rule 14).
    public function startService(User $user, string $appointmentServiceUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentServiceUuid) {
            $service = AppointmentServiceItem::whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $appointmentServiceUuid)
                ->lockForUpdate()
                ->with('therapistAssignments', 'appointment')
                ->firstOrFail();

            if ($refusal = $this->addonFollows($service, 'starts')) {
                return $refusal;
            }

            if ($stale = self::staleRefusal($service->appointment)) {
                return $stale;
            }



            // One sitting begins as one: the services this client has with the same
            // staff in the same facility start together (and are completed together).
            // A service of different staff is never started with it. All or none: a
            // refusal for any of them rolls the whole start back.
            $mates = $this->sittingMates($service);
            $together = $mates->pluck('id')->push($service->id)->all();

            $result = $this->startServiceInternal($service, $together);
            if (! $result['ok']) {
                return response()->json(['message' => $result['reason']], 422);
            }
            foreach ($mates as $mate) {
                $mate->setRelation('appointment', $service->appointment);
                $matesResult = $this->startServiceInternal($mate, $together);
                abort_if(! $matesResult['ok'], 422, $matesResult['reason']);
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($service->appointment->uuid));
        });
    }

    // Starting a home visit from the staff member's scan of the client's QR
    // (App\Support\HomeVisitStart) — through $branch's own scanner link, so
    // only that branch's bookings are ever found. Does what the desk's Check
    // In and Start do, minus the queue number: nobody is waiting at the spa.
    // It starts the first service that can start and any that may run beside
    // it; the rest follow as they do at the desk. Every rule of
    // startServiceInternal() holds. abort() rolls the whole thing back.
    public function startHomeVisitByCode(SpaBranch $branch, string $code): array
    {
        return DB::transaction(function () use ($branch, $code) {
            $appointment = Appointment::where('spa_branch_id', $branch->id)
                ->where('home_start_code', $code)
                ->lockForUpdate()
                ->first();
            abort_if(! $appointment, 422, "This code isn't for a booking at {$branch->branch_name}. Check the code, or use the scanner of the branch the client booked with.");

            $problem = HomeVisitStart::problem($appointment->status, $appointment->appointment_date, BookingPolicy::now());
            abort_if($problem !== null, 422, (string) $problem);

            $pending = AppointmentServiceItem::where('appointment_id', $appointment->id)
                ->where('status', 'Pending')
                // An add-on starts with its main service.
                ->when(Addon::ready(), fn ($q) => $q->whereNull('parent_appointment_service_id'))
                ->with('therapistAssignments')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
            abort_if($pending->isEmpty(), 422, 'There is nothing left to start on this visit.');

            // Arrived: what Check In does, without a queue number.
            if ($appointment->status === Appointment::STATUS_SCHEDULED) {
                $appointment->update(['status' => Appointment::STATUS_CHECKED_IN, 'check_in_at' => now()]);
            }

            $started = collect();
            $reason = null;
            foreach ($pending as $service) {
                $service->setRelation('appointment', $appointment);
                $result = $this->startServiceInternal($service);
                if ($result['ok']) {
                    $started->push($service);
                } else {
                    $reason ??= $result['reason'];
                }
            }
            abort_if($started->isEmpty(), 422, $this->scanRefusal($reason));

            $appointment->update(['home_scanned_at' => now()]);

            $staff = TherapistAssignment::whereIn('appointment_service_id', $started->pluck('id'))
                ->where('assignment_status', 'In Progress')
                ->whereNotNull('staff_id')
                ->with('staff')
                ->get()
                ->map(fn ($a) => trim("{$a->staff?->first_name} {$a->staff?->last_name}"))
                ->filter()
                ->unique()
                ->values();
            $services = $started->map(fn (AppointmentServiceItem $s) => $s->displayName())->filter()->values();
            $client = $appointment->client;
            $clientName = trim("{$client?->first_name} {$client?->last_name}") ?: 'A client';

            $appointment->setRelation('branch', $branch);
            $this->clientNotifier->appointment(
                $appointment,
                'Your home service has started',
                ($staff->isNotEmpty() ? $staff->join(', ', ' and ') : 'Your therapist') . ' started your ' . ($services->join(', ', ' and ') ?: 'service') . '.',
            );
            $this->staffNotifier->appointment(
                $appointment,
                'Home visit started',
                ($staff->isNotEmpty() ? $staff->join(', ', ' and ') : 'The staff') . " started {$clientName}'s home service by scan. Booking no. {$appointment->appointment_number}.",
            );

            return [
                'client_first_name' => $client?->first_name,
                'booking_number' => $appointment->appointment_number,
                'address' => $appointment->home_address,
                'services' => $services->all(),
                // Services that couldn't start beside those: they follow.
                'still_to_start' => $pending->count() - $started->count(),
                'staff' => $staff->all(),
                'started_at' => now()->toIso8601String(),
            ];
        });
    }

    // startServiceInternal()'s reasons are worded for the front desk; the
    // person holding the scanner is the staff member at the client's door.
    private function scanRefusal(?string $reason): string
    {
        if ($reason === null) {
            return 'This visit can\'t be started. Call the front desk.';
        }
        if (str_starts_with($reason, 'Assign at least one staff member')) {
            return 'No staff is assigned to this visit yet. Call the front desk.';
        }

        return str_replace(
            'Time them in on Attendance before starting this service.',
            'Ask the front desk to time them in first.',
            $reason,
        );
    }

    // Settings → Staff & Permissions → Digital check-in: with attendance
    // tracking on, a therapist has to be timed in (and not yet out) today
    // before a service of theirs can start. Assigning ahead of time is
    // unaffected — this only gates the moment the service begins.
    private function checkInProblem(Appointment $appointment, array $staffIds): ?string
    {
        if (! $staffIds) {
            return null;
        }

        // Someone removed or switched off since being assigned does not perform it
        // (and, being gone, would not show as "not timed in" either).
        $gone = Staff::withTrashed()->whereIn('id', $staffIds)->get(['id', 'first_name', 'last_name', 'status', 'deleted_at'])
            ->filter(fn (Staff $s) => $s->trashed() || $s->status === 'inactive');
        if ($gone->isNotEmpty()) {
            $names = $gone->map(fn (Staff $s) => trim("{$s->first_name} {$s->last_name}"))->implode(', ');

            return "{$names} " . ($gone->count() === 1 ? 'is' : 'are') . ' no longer on the staff. Choose someone else for this service.';
        }

        $appointment->loadMissing('branch.business.settings');
        $policy = $appointment->branch?->business?->settings?->section('staff_policy')
            ?? SpaBusinessSetting::DEFAULTS['staff_policy'];
        if (! ($policy['attendance_tracking'] ?? true) || ! ($policy['require_check_in'] ?? true)) {
            return null;
        }

        $present = Attendance::whereIn('staff_id', $staffIds)
            ->where('attendance_date', \App\Support\BusinessDay::forBranch($appointment->spa_branch_id))
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->pluck('staff_id')
            ->all();

        $missing = Staff::whereIn('id', array_diff($staffIds, $present))->get(['first_name', 'last_name']);
        if ($missing->isEmpty()) {
            return null;
        }

        $names = $missing->map(fn (Staff $s) => trim("{$s->first_name} {$s->last_name}"))->implode(', ');

        return $missing->count() === 1
            ? "{$names} hasn't timed in for today yet. Time them in on Attendance before starting this service."
            : "{$names} haven't timed in for today yet. Time them in on Attendance before starting this service.";
    }

    // Shared by startService() (explicit front-desk click) and
    // completeService()'s same-therapist auto-continue below — one place
    // for what "actually starting a service" means (guards + transition),
    // so the automatic path can't drift from the manual one.
    //
    // $together: the ids of the services starting in this same sitting (the same
    // staff in the same facility): they run side by side, so none of them counts as
    // the others' busy staff or as another service of the visit in the way.
    private function startServiceInternal(AppointmentServiceItem $service, array $together = []): array
    {
        if ($service->status !== 'Pending') {
            return ['ok' => false, 'reason' => 'This service has already started, completed, or been cancelled.'];
        }
        // Nothing starts for a client who is not here: check them in first.
        if (! in_array($service->appointment->status, [Appointment::STATUS_CHECKED_IN, Appointment::STATUS_IN_SERVICE], true)) {
            return ['ok' => false, 'reason' => 'Check the client in before starting their service.'];
        }

        // What it needs depends on its kind (ServiceKind): a therapist,
        // unless nobody performs it (a facility-based service — a sauna).
        // And a facility, when the service needs one (always for a
        // facility-based one, else the owner's tick) — except on a home
        // visit, which has none.
        $kind = $this->kindOf($service);
        $activeAssignments = $service->therapistAssignments->where('assignment_status', 'Assigned');
        if (ServiceKind::needsTherapist($kind) && $activeAssignments->whereNotNull('staff_id')->isEmpty()) {
            return ['ok' => false, 'reason' => 'Assign at least one staff member before starting this service.'];
        }
        if ($service->needsFacility() && ! $service->appointment->isHomeService() && $activeAssignments->whereNotNull('facility_id')->isEmpty()) {
            return ['ok' => false, 'reason' => 'Choose a facility for ' . ($service->displayName() ?? 'this service') . ' before starting it.'];
        }

        // One client, one place: while another service of this visit is in
        // progress, this one may only start beside it — same room (or no
        // room on either), different therapists. The visit is locked so two
        // Starts on it are judged one after the other.
        if ($problem = $this->sameVisitStartProblem($service, $activeAssignments, $together)) {
            return ['ok' => false, 'reason' => $problem];
        }

        // A procedure-based service may run beside this client's other
        // procedure-based services with the same therapist (the rule above
        // let it through), so those don't count as the therapist being busy.
        $alongside = $kind === ServiceKind::PROCEDURE
            ? AppointmentServiceItem::where('appointment_id', $service->appointment_id)
                ->where('id', '!=', $service->id)
                ->where('status', 'In Progress')
                ->with('serviceVariant.service')
                ->get()
                // An add-on counts as its main service does: it is the same
                // therapist's same sitting.
                ->filter(fn (AppointmentServiceItem $other) => $this->kindOf($other->isAddon() ? ($other->parent ?? $other) : $other) === ServiceKind::PROCEDURE)
                ->pluck('id')->all()
            : [];
        $alongside = array_values(array_unique(array_merge($alongside, $together)));

        // Staff held for another client's reservation that hasn't arrived yet
        // (App\Support\ReservationHold): this service may not run into it.
        $grace = $service->appointment->branch
            ? (int) (BookingPolicy::for($service->appointment->branch)['reservation_grace_minutes'] ?? ReservationHold::DEFAULT_GRACE_MINUTES)
            : ReservationHold::DEFAULT_GRACE_MINUTES;
        $length = $service->lengthMinutes() ?? 30;

        // Still waiting in the line (nothing of theirs started yet): nobody ahead
        // of them may be waiting for the same staff member or facility.
        $queue = $service->appointment->queue;
        $waiting = $queue && in_array($queue->queue_status, ['Waiting', 'Called'], true);
        $walkInsFirst = $service->appointment->branch ? (bool) (BookingPolicy::for($service->appointment->branch)['walk_ins_first_priority'] ?? false) : false;

        foreach ($activeAssignments as $assignment) {
            if ($waiting && ($ahead = $this->availabilityService->aheadInLine($assignment->staff_id, $assignment->facility_id, $service->appointment, $walkInsFirst))) {
                return ['ok' => false, 'reason' => $ahead];
            }
            // Room-only rows (staff_id null, rule 7) have no therapist to
            // check busy-ness for — only the facility check below applies.
            if ($assignment->staff_id) {
                $busy = $this->availabilityService->isStaffCurrentlyBusy($assignment->staff_id, $assignment->id, $alongside);
                if (! $busy['ok']) {
                    return ['ok' => false, 'reason' => $busy['reason']];
                }
                if ($held = $this->availabilityService->reservedElsewhere($assignment->staff_id, $service->appointment_id, now(), $length, $grace)) {
                    return ['ok' => false, 'reason' => $held];
                }
                // Here early for their booking: whoever is booked with the same
                // staff member before them, and is here too, is served first.
                if ($first = $this->availabilityService->bookedFirst($assignment->staff_id, $service->appointment)) {
                    return ['ok' => false, 'reason' => $first];
                }
            }

            if ($assignment->facility_id) {
                // Put under maintenance after it was picked: not started in (the
                // client in it now, if any, is left to finish).
                if ($outOfUse = $this->availabilityService->facilityOutOfUse($assignment->facility_id, $service->appointment_id)) {
                    return ['ok' => false, 'reason' => $outOfUse];
                }
                // In use by ANOTHER client's service — this visit's own service
                // in the room is the "run together" case allowed just above.
                $occupied = $this->availabilityService->isFacilityCurrentlyOccupied($assignment->facility_id, $assignment->id, $service->appointment_id);
                if (! $occupied['ok']) {
                    return ['ok' => false, 'reason' => $occupied['reason']];
                }
            }
        }

        if ($problem = $this->checkInProblem($service->appointment, $activeAssignments->pluck('staff_id')->filter()->all())) {
            return ['ok' => false, 'reason' => $problem];
        }

        // Nobody performs it and no room was picked: when it started and
        // ended is still kept, on a row with neither (the start time of
        // every service lives on its assignment).
        if ($activeAssignments->isEmpty()) {
            $activeAssignments = $activeAssignments->push($this->therapistAssignmentRepository->openSpace($service->id));
        }

        $now = now();
        foreach ($activeAssignments as $assignment) {
            $assignment->update(['assignment_status' => 'In Progress', 'started_at' => $now]);
        }
        $service->update(['status' => 'In Progress']);
        // Its add-ons start with it, on the same therapist(s) and room.
        $this->startAddons($service, $now);

        $appointment = $service->appointment;

        // First service on this appointment to actually start moves the
        // header from Checked In to In Service — a real stored status now,
        // replacing the old derived checked_in_in_service label. Stays In
        // Service (doesn't bounce back) if a later service on the same
        // appointment starts too.
        if ($appointment->canTransitionTo(Appointment::STATUS_IN_SERVICE)) {
            $appointment->update(['status' => Appointment::STATUS_IN_SERVICE]);
        }

        // served_at anchors the whole visit's "Estimated finish" (servedAt +
        // totalDurationMinutes, see QueueStatusPanel.vue/QueueView.vue) — set
        // it only the first time the ticket actually enters Serving. A later
        // service on the same visit starting (auto-continue or manual) must
        // not push it forward, or that estimate would silently inflate by
        // however long the earlier service(s) already took.
        if ($appointment->queue && $appointment->queue->queue_status !== 'Serving') {
            $this->queueRepository->updateStatus($appointment->queue, 'Serving', ['served_at' => $now]);
        }

        return ['ok' => true, 'reason' => null];
    }

    // The services of this visit that are one sitting with $service: the same
    // staff in the same facility, in the same state ($status: 'Pending' to start
    // them, 'In Progress' to complete them). Needs staff and a facility on
    // $service — without a room nothing says they are in one place. Add-ons are
    // not listed: they ride with their own main service.
    private function sittingMates(AppointmentServiceItem $service, string $status = 'Pending'): \Illuminate\Support\Collection
    {
        $assigned = $status === 'Pending' ? 'Assigned' : 'In Progress';
        $service->loadMissing('therapistAssignments');
        $mine = $service->therapistAssignments->where('assignment_status', $assigned);
        $staff = $mine->pluck('staff_id')->filter()->unique()->sort()->values()->all();
        $room = $mine->pluck('facility_id')->filter()->first();
        if (! $staff || ! $room) {
            return collect();
        }

        return AppointmentServiceItem::where('appointment_id', $service->appointment_id)
            ->where('id', '!=', $service->id)
            ->where('status', $status)
            ->when(Addon::ready(), fn ($q) => $q->whereNull('parent_appointment_service_id'))
            ->with('therapistAssignments')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(function (AppointmentServiceItem $other) use ($assigned, $staff, $room) {
                $theirs = $other->therapistAssignments->where('assignment_status', $assigned);

                return $theirs->pluck('staff_id')->filter()->unique()->sort()->values()->all() == $staff
                    && (int) $theirs->pluck('facility_id')->filter()->first() === (int) $room;
            })
            ->values();
    }

    // time | procedure | facility — a service saved before types existed is
    // time-based (App\Support\ServiceKind).
    private function kindOf(AppointmentServiceItem $service): string
    {
        return $service->kind();
    }

    // Why this service can't start beside the visit's other services that are
    // in progress, or null (App\Support\ServiceStartRules holds the rules).
    private function sameVisitStartProblem(AppointmentServiceItem $service, $activeAssignments, array $together = []): ?string
    {
        Appointment::whereKey($service->appointment_id)->lockForUpdate()->first();

        $running = AppointmentServiceItem::where('appointment_id', $service->appointment_id)
            ->where('id', '!=', $service->id)
            ->when($together, fn ($q) => $q->whereNotIn('id', $together))
            ->where('status', 'In Progress')
            // An add-on is part of its main service, which is in this list.
            ->when(Addon::ready(), fn ($q) => $q->whereNull('parent_appointment_service_id'))
            ->with([
                'serviceVariant.service',
                'therapistAssignments' => fn ($q) => $q->where('assignment_status', 'In Progress')->with('staff', 'facility'),
            ])
            ->orderBy('sort_order')
            ->get();

        if ($running->isEmpty()) {
            return null;
        }

        $activeAssignments->loadMissing('staff');
        $staffOf = fn ($assignments) => $assignments
            ->filter(fn ($a) => $a->staff_id)
            ->mapWithKeys(fn ($a) => [$a->staff_id => trim("{$a->staff?->first_name} {$a->staff?->last_name}") ?: 'The staff member'])
            ->all();

        return ServiceStartRules::problem(
            [
                'staff' => $staffOf($activeAssignments),
                'room' => $activeAssignments->pluck('facility_id')->filter()->first(),
                'kind' => $this->kindOf($service),
            ],
            $running->map(function (AppointmentServiceItem $other) use ($staffOf) {
                $withRoom = $other->therapistAssignments->first(fn ($a) => $a->facility_id);

                return [
                    'name' => $other->displayName() ?? 'another service',
                    'staff' => $staffOf($other->therapistAssignments),
                    'room' => $withRoom?->facility_id,
                    'room_name' => $withRoom?->facility?->name,
                    'kind' => $this->kindOf($other),
                ];
            })->all(),
        );
    }

    // Rejects anything not currently In Progress — the duplicate-completion
    // guard (rule 14). Flips the queue ticket to Completed only once no
    // other service on the appointment is still Pending/In Progress.
    public function completeService(User $user, string $appointmentServiceUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentServiceUuid) {
            $service = AppointmentServiceItem::whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $appointmentServiceUuid)
                ->lockForUpdate()
                ->with('therapistAssignments', 'appointment')
                ->firstOrFail();

            if ($stale = self::staleRefusal($service->appointment)) {
                return $stale;
            }

            if ($service->status !== 'In Progress') {
                return response()->json(['message' => 'Only a service currently in progress can be completed.'], 422);
            }
            if ($refusal = $this->addonFollows($service, 'is completed')) {
                return $refusal;
            }

            $now = now();
            // One sitting finishes as one: the services this client has with the
            // same staff in the same facility that are running with this one.
            $completedStaffIds = [];
            foreach ($this->sittingMates($service, 'In Progress')->prepend($service) as $line) {
                $completedStaffIds = array_merge($completedStaffIds, $this->completeLine($line, $now));
            }
            $completedStaffIds = array_values(array_unique($completedStaffIds));

            $appointment = $service->appointment;

            // Same therapist continuing straight on to the client's next
            // service needs no separate "Start Service" click — they never
            // actually left. That is the client's next waiting service that
            // THEY are assigned to — one in a different (or no) facility: the
            // ones in the same facility were started together with this one
            // and finished with it (sittingMates). A service of a different
            // therapist is never started by this: it is left Pending and
            // started on its own, as before.
            if ($completedStaffIds) {
                $nextService = $appointment->services()
                    ->where('status', 'Pending')
                    // Never an add-on: it starts with its main service.
                    ->when(Addon::ready(), fn ($q) => $q->whereNull('parent_appointment_service_id'))
                    ->whereHas('therapistAssignments', fn ($q) => $q->where('assignment_status', 'Assigned')->whereIn('staff_id', $completedStaffIds))
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->with('therapistAssignments')
                    ->first();

                if ($nextService) {
                    $this->startServiceInternal($nextService);
                }
            }

            $stillActive = $appointment->services()->whereIn('status', ['Pending', 'In Progress'])->exists();

            if (! $stillActive && $appointment->queue) {
                $this->queueRepository->updateStatus($appointment->queue, 'Completed', ['completed_at' => $now]);
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    // Completes one running service: its add-on still waiting (none should
    // be) joins first, its staff and room are released, what each earned is
    // fixed under today's Staff Policies (CommissionCalculator::freeze), and its
    // add-ons are completed with it. Returns the staff who were doing it.
    private function completeLine(AppointmentServiceItem $service, $now): array
    {
        $this->startAddons($service, $now);
        $staffIds = $service->therapistAssignments
            ->where('assignment_status', 'In Progress')
            ->pluck('staff_id')
            ->filter()
            ->all();
        foreach ($service->therapistAssignments->where('assignment_status', 'In Progress') as $assignment) {
            $assignment->update(['assignment_status' => 'Completed', 'completed_at' => $now]);
        }
        $service->update(['status' => 'Completed']);
        app(CommissionCalculator::class)->freeze($service);
        $this->completeAddons($service, $now);

        return $staffIds;
    }

    // The wallet voucher a client booked with, or a 422 saying why it can't be
    // used: not theirs, used or expired, already on another booking, or for
    // services this booking doesn't have (VoucherTarget::fits).
    private function voucherForBooking(\App\Models\Client $client, ?string $voucherUuid, array $serviceItems, ?string $firstVisitProgramUuid = null, ?int $branchId = null): \App\Models\ClientVoucher
    {
        $voucher = $voucherUuid ? \App\Models\ClientVoucher::with('program')->where('uuid', $voucherUuid)->where('client_id', $client->id)->lockForUpdate()->first() : null;
        // A first-visit voucher not claimed yet: given to the client now (only
        // while they have no paid visit), then used like any other.
        if (! $voucher && $firstVisitProgramUuid) {
            $firstVisit = \App\Models\CustomerProgram::where('uuid', $firstVisitProgramUuid)->where('spa_business_id', $client->spa_business_id)->first();
            $voucher = $firstVisit ? app(ProgramRewardService::class)->claimFirstVisit($client, $firstVisit, \App\Support\ProgramChannel::APP, $branchId) : null;
            abort_if(! $voucher, 422, "That first-visit voucher isn't available to you any more.");
            $voucher->load('program');
        }
        $program = $voucher?->dealProgram();
        abort_if(! $voucher || ! $program, 422, "That voucher isn't in your wallet.");
        abort_if(! app(ProgramRewardService::class)->voucherAllowedAt($voucher, \App\Support\ProgramChannel::APP), 422, "That voucher can't be used on the app. " . \App\Support\ProgramChannel::refusalAt(\App\Support\ProgramChannel::APP));
        abort_if(! $voucher->isUsable(), 422, $voucher->status === 'used' ? 'That voucher has already been used.' : 'That voucher has expired.');
        abort_if(\App\Models\ClientVoucher::heldIds([$voucher->id]) !== [], 422, 'That voucher is already on another booking.');

        $describe = function ($item) {
            $item->loadMissing('serviceVariant.service');

            return ['uuid' => $item->serviceVariant?->service?->uuid, 'category' => $item->category(), 'name' => $item->displayName()];
        };
        $services = collect($serviceItems)->map($describe)->all();

        abort_if(! \App\Support\VoucherTarget::fits(
            (string) $program->value('dealType', '₱ off'),
            \App\Support\VoucherTarget::of($program->values, 'usable'),
            (string) $program->value('usableOn', ''),
            $services,
        ), 422, 'This voucher is for ' . $program->value('usableOn', 'certain services') . '. Add one to your booking to use it.');

        return $voucher;
    }

    // ── Billing / payment ────────────────────────────────────────────────

    // Rejects unless every service is Completed or Cancelled (rule 12/14),
    // and rejects if a Billing row already exists for this appointment
    // (duplicate-billing guard, rule 14). Uses the appointment header's
    // total_amount directly — it's already kept current by
    // recalculateAppointmentTotals() on every add/remove, so it correctly
    // reflects the final actual availed services (originals + additions).
    public function proceedToBilling(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($business, $branchIds, $uuid) {
            $appointment = Appointment::whereIn('spa_branch_id', $branchIds)
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->with('services')
                ->firstOrFail();

            $unresolved = $appointment->services->whereNotIn('status', ['Completed', 'Cancelled'])->isNotEmpty();
            if ($unresolved) {
                return response()->json(['message' => 'All services must be completed or cancelled before billing.'], 422);
            }

            if ($this->billingRepository->findForAppointment($appointment->id)) {
                return response()->json(['message' => 'This appointment has already been billed.'], 422);
            }

            $amount = (float) $appointment->total_amount;
            if ($amount <= 0) {
                return response()->json(['message' => 'This appointment has no billable amount.'], 422);
            }

            $billing = $this->billingRepository->create([
                'appointment_id' => $appointment->id,
                'spa_business_id' => $business->id,
                'spa_branch_id' => $appointment->spa_branch_id,
                'billing_type' => 'Appointment',
                'billing_number' => $this->billingRepository->generateBillingNumber(),
                'subtotal' => $amount,
                'amount' => $amount,
                'status' => 'Pending',
                'issued_at' => now(),
            ]);

            // The voucher the client booked with goes on the bill.
            $this->billingService->applyReservedVoucher($billing);

            return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
        });
    }

    public function getBilling(User $user, string $billingUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // Full appointment context (client, branch, itemized services +
        // their therapist assignments) so the Billing & Payments detail
        // page can render Appointment Details / Bill Summary without a
        // second round trip to GET appointment/{uuid}.
        $billing = Billing::with([
            'payments.receiver',
            'appointment.client',
            'appointment.branch',
            'appointment.services.serviceVariant.service',
            'appointment.services.therapistAssignments.staff',
            'appointment.services.therapistAssignments.facility',
            // A package is charged as its own line, with its services under
            // it — without these the bill page showed the total but not the
            // package it was for.
            'appointment.services.sourceAppointmentPackage.package',
            'appointment.packages.package',
        ])
            ->where('uuid', $billingUuid)
            ->whereIn('spa_branch_id', $this->branchIds($user))
            ->firstOrFail();

        return new \App\Http\Resources\BillingResource($billing);
    }

    // Supports split/partial payments — the existing Payment model already
    // allows several rows per billing. The appointment only flips to
    // Completed once the paid total covers the billed amount (rule 13:
    // "appointment only fully completes after billing/payment").
    public function recordPayment(User $user, string $billingUuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($business, $branchIds, $billingUuid, $payload, $user) {
            $billing = Billing::where('uuid', $billingUuid)
                ->whereIn('spa_branch_id', $branchIds)
                ->lockForUpdate()
                ->firstOrFail();

            // Enabled method (Settings → Payments), reference for non-cash,
            // no overpayment, bill/appointment still open — BillingService.
            if ($rejection = $this->billingService->paymentRejection($billing, $business, $payload)) {
                return $rejection;
            }

            $tendered = isset($payload['amount_tendered']) ? round((float) $payload['amount_tendered'], 2) : null;
            $payment = $this->paymentRepository->create([
                'billing_id' => $billing->id,
                'spa_business_id' => $business->id,
                'spa_branch_id' => $billing->spa_branch_id,
                'payment_method' => $payload['payment_method'],
                'reference_number' => $payload['reference_number'] ?? null,
                'amount' => $payload['amount'],
                'amount_tendered' => $tendered,
                'change_given' => $tendered !== null ? round($tendered - (float) $payload['amount'], 2) : null,
                'payment_status' => 'Paid',
                'paid_at' => now(),
                'received_by' => $user->id,
                'remarks' => $payload['remarks'] ?? null,
            ]);

            $paidFor = $billing->appointment_id ? Appointment::find($billing->appointment_id) : null;
            if ($paidFor) {
                $amount = '₱' . number_format((float) $payload['amount'], 2);
                $this->clientNotifier->appointment(
                    $paidFor,
                    'Payment received',
                    "{$paidFor->branch?->branch_name} received your payment of {$amount} for booking {$paidFor->appointment_number}. Thank you!",
                    'Payment',
                );
                // Front desk included — they reconcile the cash drawer — but
                // not whoever just recorded it.
                $this->staffNotifier->appointment(
                    $paidFor,
                    'Payment received',
                    "{$amount} received for booking {$paidFor->appointment_number}.",
                    null,
                    'Payment',
                    exceptUserId: $user->id,
                );
            }

            // Paid in full → bill Paid, appointment Completed.
            $this->billingService->settle($billing);

            // The receipt needs the bill's remaining balance and status too.
            $payment->load('receiver');
            return (new PaymentResource($payment))->additional([
                'billing' => [
                    'uuid' => $billing->uuid,
                    'billing_number' => $billing->billing_number,
                    'amount' => (float) $billing->amount,
                    'status' => $billing->fresh()->status,
                    'balance' => $this->billingService->balance($billing),
                ],
            ]);
        });
    }

    // ── Internal helpers ─────────────────────────────────────────────────

    // Aborts the surrounding transaction (422) if the package isn't bookable
    // at the appointment's branch — same hard-integrity stance as
    // addServiceInternal(). See addPackage() for why packages explode into
    // per-component service lines.
    // $openWork: added to a visit that is already open, which may be finished just after the plan ends.
    private function addPackageInternal(Appointment $appointment, int $businessId, string $packageUuid, int $quantity, bool $openWork = false): AppointmentPackage
    {
        // Every way a package gets onto an appointment (a client's booking,
        // the front desk) passes here: the plan has to include packages.
        if (! ($openWork ? \App\Support\AppCache::planAllowsOpenWork($businessId, 'package_access') : \App\Support\AppCache::planAllows($businessId, 'package_access'))) {
            abort(422, 'Packages aren\'t available at this spa right now. Please choose services instead.');
        }

        $package = Package::where('uuid', $packageUuid)
            ->where('spa_business_id', $businessId)
            ->with(Package::contents())
            ->firstOrFail();

        $branchPackage = BranchPackage::where('package_id', $package->id)
            ->where('spa_branch_id', $appointment->spa_branch_id)
            ->where('is_available', true)
            ->first();

        if (! $branchPackage) {
            abort(422, 'This package is not available at this branch.');
        }

        // Charged as a whole, so every service in it has to still exist: one removed
        // from the menu would otherwise be skipped and the package sold short.
        foreach ($package->packageServiceItems as $item) {
            if (! $item->guideVariant()) {
                abort(422, 'This package can\'t be booked right now: ' . ($item->service?->name ?? 'one of its services') . ' is no longer on the menu.');
            }
        }

        $price = (float) ($branchPackage->custom_price ?? $package->default_price);

        $appointmentPackage = AppointmentPackage::create([
            'appointment_id' => $appointment->id,
            'package_id' => $package->id,
            'quantity' => $quantity,
            'unit_price' => $price,
            'subtotal' => $price * $quantity,
        ]);

        $nextSort = (int) ($appointment->services()->max('sort_order') ?? 0);
        // The line made for each of the package's services, for hanging its
        // included add-ons on.
        $lineOf = [];

        foreach ($package->packageServiceItems as $index => $item) {
            // A package names the service and gives it its own minutes
            // (App\Support\PackagePlan). The line still points at the menu
            // length nearest those minutes — only so the rest of the visit
            // knows which service it is (therapist skills, rooms, extending)
            // and what it lists for; the minutes the client gets are the
            // package's, kept on the line below.
            $variant = $item->guideVariant();
            if (! $variant) {
                continue;
            }

            // A package's component may not itself be individually sold
            // standalone at this branch — fall back to the variant's own
            // price so the package can still be exploded/billed
            // correctly; that's a separate concern from this branch's
            // own bookability.
            $branchService = BranchService::where('service_variant_id', $variant->id)
                ->where('spa_branch_id', $appointment->spa_branch_id)
                ->where('is_available', true)
                ->first();

            // What the owner says this service is worth in the package, else the
            // branch's or the menu's price. It is what a voided service takes off
            // the package (App\Support\PackageShare), not an extra charge.
            $componentPrice = Package::hasLinePrice() && $item->price !== null
                ? (float) $item->price
                : (float) ($branchService?->custom_price ?? $variant->price);
            $componentQty = ($item->quantity ?? 1) * $quantity;

            $line = $this->appointmentServiceRepository->create([
                'appointment_id' => $appointment->id,
                'source_appointment_package_id' => $appointmentPackage->id,
                'service_variant_id' => $variant->id,
                'quantity' => $componentQty,
                'sort_order' => $nextSort + $index + 1,
                'unit_price' => $componentPrice,
                'subtotal' => $componentPrice * $componentQty,
                'points_earned' => 0,
            ]);
            // Its minutes in this package, over the menu length's the line
            // was just given (App\Support\History keeps the line's own).
            if (History::ready() && $item->minutes() && (int) $line->duration_minutes !== $item->minutes()) {
                $line->update(['duration_minutes' => $item->minutes()]);
            }
            $lineOf[$variant->service_id] ??= $line;
        }

        // The add-ons that come with the package: each an add-on line under
        // its service, tagged to the package so it is part of the package's
        // price and not charged on top (recalculateAppointmentTotals). One
        // since removed or switched off is left out.
        if (Addon::ready()) {
            $sort = $nextSort + $package->packageServiceItems->count();
            foreach ($package->includedAddons() as $row) {
                $main = $lineOf[$row->service_id] ?? null;
                if (! $main) {
                    continue;
                }
                $price = (float) $row->addon->price;
                $this->appointmentServiceRepository->create([
                    'appointment_id' => $appointment->id,
                    'source_appointment_package_id' => $appointmentPackage->id,
                    'parent_appointment_service_id' => $main->id,
                    'addon_id' => $row->addon->id,
                    'service_name' => $row->addon->name,
                    'duration_minutes' => (int) $row->addon->duration_minutes,
                    'service_type' => ServiceKind::TIME,
                    'quantity' => 1,
                    'sort_order' => ++$sort,
                    'unit_price' => $price,
                    'discount_amount' => 0,
                    'subtotal' => $price,
                    'points_earned' => 0,
                ]);
            }
        }

        return $appointmentPackage;
    }

    // A failed requested-therapist assignment never fails the booking — it's
    // collected as a warning instead (see createAppointment()'s doc comment).
    private function applyRequestedTherapist(Appointment $appointment, int $branchId, AppointmentServiceItem $service, string $staffUuid, array &$warnings): void
    {
        $staff = $this->resolveStaffForBranch($staffUuid, $branchId);

        if (! $staff) {
            $warnings[] = 'Requested staff member not found or inactive at this branch.';
            return;
        }

        $service->load('serviceVariant.service');
        // The therapist the booking asked for is for the services someone
        // performs — a facility-based one is simply left without.
        if (! ServiceKind::needsTherapist($this->kindOf($service))) {
            return;
        }
        $result = $this->assignTherapistInternal($service, $appointment, $staff);
        if (! $result['ok']) {
            $warnings[] = $result['reason'];
        }
    }

    // The booking asked for this therapist by name: kept on the appointment
    // (Appointment::requestedStaff), whether or not they could be assigned.
    private function rememberRequestedTherapist(Appointment $appointment, int $branchId, ?string $staffUuid): void
    {
        if (! $staffUuid || ! Appointment::remembersRequests()) {
            return;
        }
        $staff = $this->resolveStaffForBranch($staffUuid, $branchId);
        if ($staff) {
            $appointment->forceFill(['requested_staff_id' => $staff->id])->save();
        }
    }

    private function resolveStaffForBranch(string $staffUuid, int $branchId): ?Staff
    {
        return Staff::where('uuid', $staffUuid)
            ->where('spa_branch_id', $branchId)
            ->where('status', 'active')
            ->first();
    }

    // Aborts the surrounding transaction (422) if the variant isn't
    // bookable at this branch at all — a hard data-integrity condition, not
    // a soft business-rule branch like the availability checks below.
    private function addServiceInternal(Appointment $appointment, int $branchId, array $item, ?int $sourcePackageId, int $sortOrder): AppointmentServiceItem
    {
        $variant = ServiceVariant::where('uuid', $item['service_variant_uuid'])->firstOrFail();

        $branchService = BranchService::where('service_variant_id', $variant->id)
            ->where('spa_branch_id', $branchId)
            ->where('is_available', true)
            ->first();

        if (! $branchService) {
            abort(422, 'This service is not available at the selected branch.');
        }

        $quantity = $item['quantity'] ?? 1;
        $price = (float) ($branchService->custom_price ?? $variant->price);

        // Removing a service is a status flip, not a delete (rule 17:
        // preserve history) — so re-adding the exact same variant right
        // after would otherwise pile up a second Cancelled+Pending pair for
        // it instead of just reactivating the one that's already there.
        // Package-exploded lines are excluded: reactivating one line from a
        // cancelled package item is a different question (the whole package
        // purchase, not just this line) and isn't what this covers.
        if ($sourcePackageId === null) {
            $existingCancelled = $appointment->services()
                ->where('service_variant_id', $variant->id)
                ->whereNull('source_appointment_package_id')
                ->where('status', 'Cancelled')
                ->first();

            if ($existingCancelled) {
                $existingCancelled->update([
                    'status' => 'Pending',
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'discount_amount' => $item['discount_amount'] ?? 0,
                    'subtotal' => $price * $quantity,
                    'points_earned' => 0,
                    'notes' => $item['notes'] ?? null,
                    'sort_order' => $sortOrder,
                    // Booked anew: the saving hook takes a fresh copy of the
                    // name and length (App\Support\History).
                ] + (History::ready() ? ['service_name' => null] : []));

                return $existingCancelled;
            }
        }

        return $this->appointmentServiceRepository->create([
            'appointment_id' => $appointment->id,
            'source_appointment_package_id' => $sourcePackageId,
            'service_variant_id' => $variant->id,
            'quantity' => $quantity,
            'sort_order' => $sortOrder,
            'unit_price' => $price,
            'discount_amount' => $item['discount_amount'] ?? 0,
            'subtotal' => $price * $quantity,
            'points_earned' => 0,
            'notes' => $item['notes'] ?? null,
        ]);
    }

    // Qualification -> schedule, in that order, so the first failing check
    // gives the most useful reason. Never throws — the caller decides
    // whether a failure is fatal (explicit assignTherapist action) or just
    // a warning (requested therapist at creation time). Deliberately no
    // double-booking check here — reservations aren't time-blocked (walk-in
    // queue model, no real per-service time slots); the only real conflict
    // check is the real-time isStaffCurrentlyBusy() at startService().
    //
    // The schedule check is skipped entirely once the therapist has
    // actually checked in for attendance on this date — a real, in-person
    // fact overrides a static weekly shift definition (day off included):
    // if they're physically here and clocked in, front desk can assign them
    // regardless of what their configured schedule says. Qualification is
    // never bypassed this way — that's a hard skill requirement, not a
    // presence one.
    private function assignTherapistInternal(AppointmentServiceItem $service, Appointment $appointment, Staff $staff): array
    {
        if ($staff->role !== 'therapist') {
            return ['ok' => false, 'reason' => 'This employee doesn\'t perform services.', 'assignment' => null];
        }
        if (! ServiceKind::needsTherapist($this->kindOf($service))) {
            return ['ok' => false, 'reason' => 'This service needs no staff.', 'assignment' => null];
        }
        // A home visit goes to staff the owner has marked for home service —
        // never bypassed, like qualification.
        if ($appointment->isHomeService() && ($refusal = HomeServiceRules::staffRefusal($staff))) {
            return ['ok' => false, 'reason' => $refusal, 'assignment' => null];
        }

        $duration = $service->lengthMinutes() ?? 30;
        $date = $appointment->appointment_date->format('Y-m-d');

        $checks = [
            $this->availabilityService->isStaffQualified($staff->id, $service->serviceVariant->service_id),
        ];

        $isCheckedIn = Attendance::where('staff_id', $staff->id)
            ->where('attendance_date', \App\Support\BusinessDay::attendanceDate($appointment->spa_branch_id, $date))
            ->whereNotNull('check_in_at')
            ->exists();

        if (! $isCheckedIn) {
            $checks[] = $this->availabilityService->staffMatchesSchedule($staff->id, $date, $appointment->appointment_time, $duration);
        }

        foreach ($checks as $check) {
            if (! $check['ok']) {
                return $check + ['assignment' => null];
            }
        }

        // One staff member per service: someone already on it who hasn't
        // started is replaced (their row, and the room on it, are kept);
        // once it is under way the staff can't be changed here.
        $current = $service->therapistAssignments()
            ->whereIn('assignment_status', ['Assigned', 'In Progress'])
            ->whereNotNull('staff_id')
            ->first();
        if ($current) {
            if ($current->assignment_status === 'In Progress') {
                return ['ok' => false, 'reason' => 'This service has already started with its staff member.', 'assignment' => null];
            }
            if ($current->staff_id !== $staff->id) {
                $current->update(['staff_id' => $staff->id, 'assigned_at' => now()]);
            }

            return ['ok' => true, 'reason' => null, 'assignment' => $current->fresh()];
        }

        // If a room was already reserved for this service ahead of a
        // therapist, claim that same row rather than creating a second one
        // — preserves the room instead of losing/duplicating it.
        $unclaimed = $this->therapistAssignmentRepository->findUnclaimedForService($service->id);
        $assignment = $unclaimed
            ? $this->therapistAssignmentRepository->claim($unclaimed, $staff->id)
            : $this->therapistAssignmentRepository->assign($service->id, $staff->id);

        return ['ok' => true, 'reason' => null, 'assignment' => $assignment];
    }

    // Unlike assignTherapistInternal() above, rooms ARE time-blocked: a
    // physical room can't serve two appointments at once, so both the
    // service-support check and isFacilityAvailable() are hard gates here,
    // not advisory (see AppointmentAvailabilityService's class doc comment).
    private function assignRoomInternal(TherapistAssignment $assignment, Appointment $appointment, AppointmentServiceItem $service, string $facilityUuid): array
    {
        $facility = Facility::where('uuid', $facilityUuid)->where('spa_branch_id', $appointment->spa_branch_id)->first();
        if (! $facility) {
            return ['ok' => false, 'reason' => 'Facility not found at this branch.'];
        }

        $roomCheck = $this->checkRoomEligibility($facility, $appointment, $service, $assignment->id);
        if (! $roomCheck['ok']) {
            return $roomCheck;
        }

        $this->therapistAssignmentRepository->assignFacility($assignment, $facility->id);

        return ['ok' => true, 'reason' => null];
    }

    // Reserves a room for a service with no therapist assigned yet (rule:
    // room independent of staff). Same hard gates as assignRoomInternal().
    private function assignRoomOnlyInternal(AppointmentServiceItem $service, Appointment $appointment, string $facilityUuid): array
    {
        $facility = Facility::where('uuid', $facilityUuid)->where('spa_branch_id', $appointment->spa_branch_id)->first();
        if (! $facility) {
            return ['ok' => false, 'reason' => 'Facility not found at this branch.'];
        }

        $roomCheck = $this->checkRoomEligibility($facility, $appointment, $service, null);
        if (! $roomCheck['ok']) {
            return $roomCheck;
        }

        $this->therapistAssignmentRepository->assignRoomOnly($service->id, $facility->id);

        return ['ok' => true, 'reason' => null];
    }

    // Shared by assignRoomInternal()/assignRoomOnlyInternal(): the room must
    // support the service being booked, and must be free for the
    // appointment's estimated time window (excluding this same assignment
    // row, if one already exists, so re-confirming a room doesn't conflict
    // with itself). A room with NO services configured at all is treated as
    // unrestricted (open to any service) rather than rejected outright —
    // this keeps every pre-existing room (created before this feature, or
    // simply not yet categorized) working exactly as before instead of
    // silently locking out every assignment until an owner manually
    // back-fills every room's service list.
    private function checkRoomEligibility(Facility $facility, Appointment $appointment, AppointmentServiceItem $service, ?int $excludeAssignmentId): array
    {
        // The client's own room — one of their other services is in it — is
        // fine whatever it is set up for: they stay put, and the same staff can
        // do both in one sitting (sittingMates).
        $theirs = TherapistAssignment::where('facility_id', $facility->id)
            ->whereIn('assignment_status', ['Assigned', 'In Progress'])
            ->whereHas('appointmentService', fn ($q) => $q->where('appointment_id', $appointment->id)->where('id', '!=', $service->id))
            ->exists();
        $serviceId = $service->serviceVariant?->service_id;
        if (! $theirs && $serviceId && $facility->services()->exists() && ! $facility->services()->where('services.id', $serviceId)->exists()) {
            return ['ok' => false, 'reason' => 'This facility does not support the selected service.'];
        }

        // Under maintenance: nobody new (whoever is in it now finishes first).
        if ($outOfUse = $this->availabilityService->facilityOutOfUse($facility->id, $appointment->id)) {
            return ['ok' => false, 'reason' => $outOfUse];
        }

        $duration = $service->lengthMinutes() ?? 30;
        $date = $appointment->appointment_date->format('Y-m-d');

        return $this->availabilityService->isFacilityAvailable($facility->id, $date, $appointment->appointment_time, $duration, $excludeAssignmentId, $appointment->id);
    }
}

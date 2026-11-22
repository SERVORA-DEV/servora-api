<?php

namespace App\Service\Business;

use App\Enums\AppointmentServiceStatus;
use App\Enums\AppointmentStatus;
use App\Enums\AssignmentStatus;
use App\Enums\QueueStatus;
use App\Enums\VoucherStatus;
use App\Exceptions\ActionRefused;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\Client\ClientAppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentPackage;
use App\Models\AppointmentServiceItem;
use App\Models\Client;
use App\Models\ClientVoucher;
use App\Models\CustomerProgram;
use App\Models\Package;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Repository\Business\AppointmentRepository;
use App\Repository\Business\AppointmentServiceRepository;
use App\Repository\Business\ClientRepository;
use App\Repository\Business\QueueRepository;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Service\Client\BookingPolicy;
use App\Service\Client\BookingSlotService;
use App\Service\Client\ClientBookingService;
use App\Service\Subscription\PlanAccessService;
use App\Support\ClientProfile;
use App\Support\Visits\FrontDeskPolicy;
use App\Support\Branches\HourlyCapacity;
use App\Support\Programs\ProgramChannel;
use App\Support\Catalog\ServiceKind;
use App\Support\Catalog\ServiceParts;
use App\Support\Platform\Suspension;
use App\Support\Programs\VoucherTarget;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Booking a visit and changing the booking: made at the desk or from the client app (with
 * companions on a shared package), cancelled, moved, or marked a no-show. Check-in, the services
 * on the visit and who does them are their own classes (CheckInService, VisitItemsService,
 * VisitAssignmentService); a booking asks them for what it needs.
 *
 * Split out of AppointmentService (which still hands every call on, for its older callers).
 */
class AppointmentBookingService
{
    public function __construct(
        private AppointmentRepository $appointmentRepository,
        private AppointmentServiceRepository $appointmentServiceRepository,
        private QueueRepository $queueRepository,
        private ClientRepository $clientRepository,
        private ClientService $clientService,
        private SpaBusinessRepository $spaBusinessRepository,
        private SpaBranchRepository $spaBranchRepository,
        private AppointmentAvailabilityService $availabilityService,
        private ClientNotifier $clientNotifier,
        private StaffNotifier $staffNotifier,
        private CheckInService $checkIns,
        private VisitAssignmentService $assignments,
        private VisitItemsService $items,
    ) {}

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
            // Joining a package Client A booked for several clients: same branch, date and time
            // as Client A, and a place still open (SharedPackages).
            $joining = null;
            if (! empty($payload['join_package_uuid'])) {
                $joining = AppointmentPackage::where('uuid', $payload['join_package_uuid'])->whereNull('shared_from_id')
                    ->whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                    ->with(['appointment.branch', 'package' => fn ($q) => $q->withTrashed()])
                    ->lockForUpdate()->first();
                throw_if(! $joining || $joining->status === AppointmentServiceStatus::Cancelled->value || \App\Support\Catalog\SharedPackages::clientsOf($joining) < 2, ActionRefused::class, 'That package booking was not found.');
                throw_if(in_array($joining->appointment->status, [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value, AppointmentStatus::Completed->value], true), ActionRefused::class, 'That booking is no longer open, so no one can join its package.');
                throw_if(\App\Support\Catalog\SharedPackages::nextSlot($joining) === null, ActionRefused::class, "{$joining->displayName()} already has all its " . \App\Support\Catalog\SharedPackages::clientsOf($joining) . ' clients.');
                $payload['spa_branch_uuid'] = $joining->appointment->branch->uuid;
                $payload['appointment_date'] = $joining->appointment->appointment_date->format('Y-m-d');
                $payload['appointment_time'] = substr((string) $joining->appointment->appointment_time, 0, 5);
                $payload['appointment_type'] = $joining->appointment->appointment_type === 'Walk-in' ? 'Walk-in' : 'Reservation';
                unset($payload['package_uuid']);
            }

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
                && ($closed = $this->checkIns->closedNowRefusal($branch->id, 'a walk-in can\'t be added yet'))) {
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
                    return response()->json(['message' => 'A walk-in can\'t be a home service. Add it as a booking instead.'], 422);
                }
                $home = HomeServiceRules::check($branch, $payload);
                if (! $home['ok']) {
                    return response()->json(['message' => $home['reason']], 422);
                }
                if (! empty($payload['requested_therapist_uuid'])
                    && ($asked = $this->assignments->resolveStaffForBranch($payload['requested_therapist_uuid'], $branch->id))
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

            throw_if($joining && $client->id === $joining->appointment->client_id, ActionRefused::class,
                'That is the client who booked the package. Choose the other client.');

            // A reservation needs a way to reach the client (a change of plan,
            // a reminder, a no-show): a phone number on record, or given with
            // the booking just above. A walk-in is here, so it can do without.
            // a refusal rolls back a client record made a moment ago.
            throw_if(($payload['appointment_type'] ?? 'Reservation') !== 'Walk-in' && blank($client->phone_number), ActionRefused::class,
                "Add the client's phone number to add a booking.");

            // Someone is going to their door: a phone number and the gender
            // come first (App\Support\ClientProfile). a refusal rolls back a
            // client record made a moment ago.
            if ($home && Client::hasProfile()) {
                $missing = ClientProfile::missingForHome(['phone_number' => $client->phone_number, 'gender' => $client->genderShown()]);
                throw_if((bool) $missing, ActionRefused::class, ClientProfile::homeMessage($missing));
            }

            // One visit at a time: a client already here today — in the queue
            // or being served on the floor — can't be booked again for today
            // until that visit is finished or cancelled. (A later day is fine.)
            if ($payload['appointment_date'] === now()->toDateString() && ($here = $this->checkIns->visitHereNow($client->id))) {
                $name = trim("{$client->first_name} {$client->last_name}") ?: 'This client';
                $where = $here->status === AppointmentStatus::InService->value
                    ? 'being served right now'
                    : 'already in the queue'.($here->queue?->queue_number ? " (ticket {$here->queue->queue_number})" : '');

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
                'status' => $isWalkIn ? AppointmentStatus::CheckedIn->value : AppointmentStatus::Scheduled->value,
                'check_in_at' => $isWalkIn ? now() : null,
                'remarks' => $payload['remarks'] ?? null,
            ] + ($home ? HomeServiceRules::attributes($payload, $home) : []) + $this->startAttributes($payload, (bool) $home));

            $warnings = [];
            $this->assignments->rememberRequestedTherapist($appointment, $branch->id, $payload['requested_therapist_uuid'] ?? null);

            foreach (array_values($payload['services'] ?? []) as $index => $item) {
                $service = $this->items->addServiceInternal($appointment, $branch->id, $item, null, $index + 1);

                // Done by several staff at once: who the desk picked for each part. A warning, not a
                // refusal, like the requested therapist; empty parts are filled below.
                if (! empty($item['staff'])) {
                    foreach ($this->assignments->chooseParts($appointment, $branch->id, $service, $item['staff']) as $problem) {
                        $warnings[] = $problem;
                    }
                } elseif (! empty($payload['requested_therapist_uuid'])) {
                    $this->assignments->applyRequestedTherapist($appointment, $branch->id, $service, $payload['requested_therapist_uuid'], $warnings);
                }
            }

            // A package booked with it: its services go on as lines of their
            // own (see addPackage()), each offered to the requested therapist.
            if (! empty($payload['package_uuid'])) {
                $appointmentPackage = $this->items->addPackageInternal($appointment, $business->id, $payload['package_uuid'], 1);

                if (! empty($payload['requested_therapist_uuid'])) {
                    foreach ($appointment->services()->where('source_appointment_package_id', $appointmentPackage->id)->get() as $service) {
                        $this->assignments->applyRequestedTherapist($appointment, $branch->id, $service, $payload['requested_therapist_uuid'], $warnings);
                    }
                }
            }

            // Their place in the package Client A booked: their own services, nothing to pay.
            if ($joining) {
                $this->items->addPackageInternal($appointment, $business->id, $joining->package->uuid, 1, false, $joining);
            }

            // Every service on a home visit must be one the owner marked for
            // home service. a refusal rolls the booking back.
            if ($home && ($blocked = HomeServiceRules::ineligibleServices($appointment))) {
                throw new ActionRefused(HomeServiceRules::ineligibleMessage($blocked));
            }

            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

            // A reservation: its facility is picked now (autoAssign), and its staff too when none was asked for.
            // What could not be placed comes back as warnings for the desk. A named therapist keeps the request
            // handled above and only gets a room. A walk-in is assigned when it is started.
            if (! $isWalkIn) {
                $autoStaff = empty($payload['requested_therapist_uuid']) && ($policy['auto_assign_staff'] ?? true);
                $warnings = array_merge($warnings, $this->assignments->autoAssign($appointment, $autoStaff ? 'missing' : 'facility_only'));
            }
            // A service done by several staff where someone was picked for a part: the other parts
            // get someone now, so picking one is enough.
            $warnings = array_merge($warnings, $this->assignments->fillPickedParts($appointment));

            if ($isWalkIn) {
                $this->checkIns->addToQueueInternal($appointment);
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
                        .Carbon::parse("{$payload['appointment_date']} {$payload['appointment_time']}")->format('D, M j \\a\\t g:i A')
                        .". Booking no. {$appointment->appointment_number}.",
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
            $mine = Client::where('spa_business_id', $branch->spa_business_id)->where('user_id', $user->id)->value('id');
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

        // A booking of theirs (at any spa) that runs over this time is refused:
        // nobody can be in two places at once. Older apps still send
        // acknowledge_overlap from when this was only a warning; it no longer
        // lets the booking through.
        $clash = $this->clientClash($user, $payload['appointment_date'], $payload['appointment_time'], $this->requestedMinutes($payload) ?? 30);
        if ($clash) {
            return response()->json([
                'message' => $this->clientClashMessage($clash),
                'code' => 'overlaps_existing_booking',
            ], 422);
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
            // Serialize the business-wide monthly allowance across all branches.
            SpaBusiness::whereKey($branch->spa_business_id)->lockForUpdate()->first();
            app(PlanAccessService::class)->assertCanBook($branch->spa_business_id);
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
                'status' => AppointmentStatus::Scheduled->value,
                'remarks' => $payload['remarks'] ?? null,
            ] + ($home ? HomeServiceRules::attributes($payload, $home) : []) + $this->startAttributes($payload, (bool) $home));

            $serviceItems = [];

            $picks = [];
            foreach (array_values($payload['services'] ?? []) as $index => $item) {
                $serviceItems[] = $line = $this->items->addServiceInternal(
                    $appointment,
                    $branch->id,
                    ['service_variant_uuid' => $item['service_variant_uuid']],
                    null,
                    $index + 1,
                );
                // Done by several staff at once: who the client picked for each part (only where
                // the spa lets clients choose their therapist).
                if (! empty($item['staff']) && BookingPolicy::clientMayChooseTherapist($branch)) {
                    $picks[] = [$line, $item['staff']];
                }
            }

            if (! empty($payload['package_uuid'])) {
                $appointmentPackage = $this->items->addPackageInternal($appointment, $branch->spa_business_id, $payload['package_uuid'], 1);
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

            // Or a discount their membership includes, held the same way. One program a booking:
            // the request refuses a voucher and a discount together.
            if (! empty($payload['discount_program_uuid'])) {
                throw_if(! Appointment::holdsDiscounts(), ActionRefused::class, 'Member discounts can\'t be booked in the app yet. Ask the spa to apply it at the counter.');
                throw_if(! empty($payload['voucher_uuid']) || ! empty($payload['first_visit_program_uuid']), ActionRefused::class, 'Use either a voucher or your member discount on a booking, not both.');
                $discount = app(ProgramRewardService::class)->memberDiscountFor($client, $payload['discount_program_uuid'], ProgramChannel::APP);
                throw_if(is_string($discount), ActionRefused::class, is_string($discount) ? $discount : '');
                Appointment::whereKey($appointment->id)->update(['discount_program_id' => $discount->id]);
            }

            $warnings = [];

            // Every service on a home visit must be one the owner marked for
            // home service (a stale menu, or a direct call). Rolls back.
            if ($home && ($blocked = HomeServiceRules::ineligibleServices($appointment))) {
                throw new ActionRefused(HomeServiceRules::ineligibleMessage($blocked));
            }

            // Now that the services are on, the visit's real length is known:
            // it must end before closing and leave a
            // therapist free for it (BookingSlotService — the same check the
            // app's time chips were built from). a refusal rolls this back.
            $appointment->load('services.serviceVariant');

            // The branch's "service time per booking" limit. A hard stop for
            // a client booking alone; the front desk is only warned about it.
            $serviceTimeProblem = BookingPolicy::serviceTimeProblem(
                BookingPolicy::for($branch),
                $this->availabilityService->estimatedDurationMinutes($appointment),
                (int) $appointment->services->map(fn ($s) => (int) ($s->serviceVariant?->duration_minutes ?? 0) * max(1, (int) ($s->quantity ?? 1)))->max(),
                $appointment->services->contains(fn ($s) => $s->source_appointment_package_id !== null),
            );
            throw_if($serviceTimeProblem !== null, ActionRefused::class, (string) $serviceTimeProblem);

            $need = ServiceParts::needOf($appointment);
            // Only facility-based services (a sauna): nobody does it, so a therapist sent with it is dropped.
            if ($need === 0) {
                unset($payload['requested_therapist_uuid']);
            }
            $requestedStaff = ! empty($payload['requested_therapist_uuid'])
                ? $this->assignments->resolveStaffForBranch($payload['requested_therapist_uuid'], $branch->id)
                : null;
            $slotProblem = app(BookingSlotService::class)->bookingProblem(
                $branch,
                $payload['appointment_date'],
                substr($payload['appointment_time'], 0, 5),
                max($this->availabilityService->estimatedDurationMinutes($appointment), 30),
                $requestedStaff,
                $appointment->id,
                (bool) $home,
                $need,
                ServiceParts::roomServicesOf($appointment),
            );
            throw_if($slotProblem !== null, ActionRefused::class, (string) $slotProblem);

            // The people picked for each part: free and able to do it (a hard gate, as for the
            // requested therapist below), then put on their parts.
            foreach ($picks as [$line, $chosen]) {
                foreach ($chosen as $pick) {
                    $this->assertRequestedTherapistIsFree($appointment, $branch->id, $pick['staff_uuid'], $payload['appointment_date'], $payload['appointment_time'], $line);
                }
                $problems = $this->assignments->chooseParts($appointment, $branch->id, $line, $chosen);
                throw_if((bool) $problems, ActionRefused::class, implode(' ', $problems));
            }

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
                $this->assignments->rememberRequestedTherapist($appointment, $branch->id, $payload['requested_therapist_uuid']);

                foreach ($serviceItems as $service) {
                    $this->assignments->applyRequestedTherapist($appointment, $branch->id, $service, $payload['requested_therapist_uuid'], $warnings);
                }
            }

            // No therapist asked for ("any"): the booking takes one of the free therapists in the slot
            // count (BookingSlotService), and staff and a facility are picked for it now (autoAssign): one
            // person for the whole visit when someone can do it, else the best fit per service, among those
            // on shift that day, qualified, not on leave and not already booked, with the client's
            // staff-gender preference in view. (Picking whoever came first used to hold also staff with no
            // shift that day; that is what the availability checks there rule out.) What could not be placed
            // is left for the front desk, which sees it in the Assignments list. The client is told nothing
            // about it: the warnings are for the desk.
            // The facility is picked either way: a named therapist (or automatic staff turned off) still gets a room.
            $this->assignments->autoAssign($appointment, empty($payload['requested_therapist_uuid']) && ($policy['auto_assign_staff'] ?? true) ? 'missing' : 'facility_only');
            // A part the client (or the requested therapist) took: the other parts get someone now.
            $this->assignments->fillPickedParts($appointment);

            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

            // A package bought for several clients: the others, each their own appointment.
            $this->bookCompanions($appointment, $branch, $payload, $appointmentPackage ?? null, $home, $policy, $warnings);

            $appointment->setRelation('branch', $branch);
            $this->clientNotifier->appointment(
                $appointment,
                'Booking confirmed',
                "You're booked at {$branch->branch_name} on "
                    .Carbon::parse("{$payload['appointment_date']} {$payload['appointment_time']}")->format('D, M j \\a\\t g:i A')
                    .". Your booking number is {$appointment->appointment_number}.",
            );

            $this->staffNotifier->appointment(
                $appointment,
                'New booking',
                trim("{$client->first_name} {$client->last_name}").' booked '
                    .Carbon::parse("{$payload['appointment_date']} {$payload['appointment_time']}")->format('D, M j \\a\\t g:i A')
                    .". Booking no. {$appointment->appointment_number}.",
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

    // The other clients of a package the client booked for several (a Couples Massage): each gets
    // their own appointment at the same date and time, joined to the package Client A paid for
    // (SharedPackages), with their own staff. With two clients each may name a therapist (where the
    // spa lets clients choose); with more, the spa assigns them. a refusal rolls everything back.
    private function bookCompanions(Appointment $appointment, SpaBranch $branch, array $payload, ?AppointmentPackage $bought, ?array $home, array $policy, array &$warnings): void
    {
        $companions = array_values($payload['companions'] ?? []);
        $clients = $bought ? \App\Support\Catalog\SharedPackages::clientsOf($bought->loadMissing('package')) : 1;
        if ($clients < 2) {
            throw_if((bool) $companions, ActionRefused::class, 'Only a package for several clients can be booked with others.');

            return;
        }
        $others = $clients - 1;
        throw_if(count($companions) !== $others, ActionRefused::class, "{$bought->displayName()} is for {$clients} clients: add the details of the other " . ($others === 1 ? 'one' : $others) . '.');

        $mayChoose = $clients === 2 && BookingPolicy::clientMayChooseTherapist($branch);
        $time = substr((string) $appointment->appointment_time, 0, 5);
        foreach ($companions as $companion) {
            $client = $this->clientService->findOrCreate($branch->spa_business_id, array_filter([
                'first_name' => $companion['first_name'],
                'last_name' => $companion['last_name'],
                'phone_number' => $companion['phone_number'] ?? null,
                'gender' => $companion['gender'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''), $branch->id);
            throw_if($client->id === $appointment->client_id, ActionRefused::class, 'Your companion\'s details match yours. Please enter theirs.');

            $theirs = $this->appointmentRepository->create([
                'spa_branch_id' => $branch->id,
                'client_id' => $client->id,
                'appointment_number' => $this->appointmentRepository->generateAppointmentNumber(),
                'appointment_date' => $appointment->appointment_date->format('Y-m-d'),
                'appointment_time' => $time,
                'appointment_type' => 'Reservation',
                'source' => 'Mobile',
                'status' => AppointmentStatus::Scheduled->value,
            ] + ($home ? HomeServiceRules::attributes($payload, $home) : []) + $this->startAttributes($payload, (bool) $home));
            $this->items->addPackageInternal($theirs, $branch->spa_business_id, $bought->package->uuid, 1, false, $bought);

            $named = $mayChoose && ! empty($companion['therapist_uuid']) ? $companion['therapist_uuid'] : null;
            if ($named) {
                $this->assertRequestedTherapistIsFree($theirs, $branch->id, $named, $theirs->appointment_date->format('Y-m-d'), $time);
                $this->assignments->rememberRequestedTherapist($theirs, $branch->id, $named);
                foreach ($theirs->services()->get() as $line) {
                    $this->assignments->applyRequestedTherapist($theirs, $branch->id, $line, $named, $warnings);
                }
            }
            $this->assignments->autoAssign($theirs, ! $named && ($policy['auto_assign_staff'] ?? true) ? 'missing' : 'facility_only');
            $this->assignments->fillPickedParts($theirs);
            $this->appointmentServiceRepository->recalculateAppointmentTotals($theirs);
        }
    }

    // Client self-booking only. Runs the same schedule-then-booked checks,
    // in the same order, as the availability endpoint the app's therapist
    // picker was filtered by, and refuses (rolling back the surrounding transaction) the
    // way addServiceInternal() does for an unbookable service — so a
    // rejection leaves no half-built appointment behind.
    //
    // Called once per appointment rather than per service: every service on
    // an appointment shares its one appointment_date/appointment_time, so
    // the window being checked is the same for all of them. The duration is
    // read off the already-added services, which is why this runs after
    // they're attached.
    // What a booking keeps for App\Support\Visits\HomeVisitStart: a start code when it
    // is a home visit. Nothing until the columns exist (Appointment::hasHomeStart).
    private function startAttributes(array $payload, bool $isHome): array
    {
        if (! Appointment::hasHomeStart()) {
            return [];
        }

        return [
            'home_start_code' => $isHome ? Appointment::newHomeStartCode() : null,
        ];
    }

    // $only: someone picked for a part of one service only has to be able to do that one.
    private function assertRequestedTherapistIsFree(
        Appointment $appointment,
        int $branchId,
        string $staffUuid,
        string $date,
        string $time,
        ?AppointmentServiceItem $only = null,
    ): void {
        $staff = $this->assignments->resolveStaffForBranch($staffUuid, $branchId);

        // Left to applyRequestedTherapist's existing warning: an unknown or
        // inactive therapist isn't a scheduling conflict, and the booking is
        // still perfectly servable by whoever the branch assigns instead.
        if (! $staff) {
            return;
        }

        $appointment->load('services.serviceVariant.service');

        // A home visit needs someone the owner has marked for home service.
        if ($appointment->isHomeService() && ($refusal = HomeServiceRules::staffRefusal($staff))) {
            throw new ActionRefused($refusal);
        }

        // They have to be able to perform what is booked (a client can't be
        // given someone the owner has not qualified for it).
        foreach ($appointment->services as $item) {
            if (! ServiceKind::needsTherapist($item->kind()) || ($only && $item->id !== $only->id)) {
                continue;
            }
            $serviceId = $item->serviceVariant?->service_id;
            if ($serviceId && ! $this->availabilityService->isStaffQualified($staff->id, $serviceId)['ok']) {
                throw new ActionRefused(trim("{$staff->first_name} {$staff->last_name}").' does not perform '.($item->serviceVariant->service->name ?? 'this service').'. Please pick another therapist.');
            }
        }
        // Same 30-minute floor busyWindowsForBranch() applies: an
        // appointment whose services carry no duration would otherwise
        // collapse to a zero-width window that can never overlap anything.
        $duration = max($this->availabilityService->estimatedDurationMinutes($appointment), 30);

        $schedule = $this->availabilityService->staffMatchesSchedule($staff->id, $date, $time, $duration);
        if (! $schedule['ok']) {
            throw new ActionRefused($schedule['reason']);
        }

        // A home visit keeps them for the trip there and back too.
        [$from, $span] = HomeServiceRules::widen($appointment->isHomeService(), $branchId, $date, $time, $duration);
        $booked = $this->availabilityService->isStaffAvailable($staff->id, $date, $from, $span);
        if (! $booked['ok']) {
            throw new ActionRefused($booked['reason']);
        }
    }

    public function markNoShow(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));

        if (! $appointment->canTransitionTo(AppointmentStatus::NoShow->value)) {
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
            $appointment->update(['status' => AppointmentStatus::NoShow->value]);

            $serviceIds = $appointment->services()->pluck('id');
            $appointment->services()->where('status', AppointmentServiceStatus::Pending->value)->update(['status' => AppointmentServiceStatus::Cancelled->value]);
            TherapistAssignment::whereIn('appointment_service_id', $serviceIds)
                ->where('assignment_status', AssignmentStatus::Assigned->value)
                ->update(['assignment_status' => AssignmentStatus::Cancelled->value]);

            if ($appointment->queue) {
                $this->queueRepository->updateStatus($appointment->queue, QueueStatus::Cancelled->value);
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
                    .($action === 'suspend'
                        ? " You have now missed {$missed} bookings, so booking through the app is paused. Please contact {$spa} to book."
                        : ($missed > 1 ? " You have now missed {$missed} bookings." : '')
                            .($policy['no_show_policy'] === 'suspend' ? " After {$policy['no_show_suspend_after']} missed bookings, booking through the app will be paused." : '')),
            );
        }
        $this->staffNotifier->appointment(
            $appointment,
            'Client no-show',
            trim("{$appointment->client?->first_name} {$appointment->client?->last_name}")." didn't arrive for booking {$appointment->appointment_number}.",
            'on_client_no_show',
        );

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
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value])
            ->when($exceptAppointmentId, fn ($q) => $q->where('id', '!=', $exceptAppointmentId))
            ->count();
    }

    /**
     * A booking of this signed-in client (at any spa) whose time runs over
     * [$date $time, +$minutes): nobody can be in two places at once, so a new
     * booking or a move onto it is refused. Each booking's span is its services'
     * length (AppointmentAvailabilityService::estimatedDurationMinutes).
     * Cancelled, missed and finished visits don't count; $exceptAppointmentId is
     * the booking being moved.
     */
    public function clientClash(User $user, string $date, string $time, int $minutes, ?int $exceptAppointmentId = null): ?Appointment
    {
        $start = Carbon::parse($date.' '.substr($time, 0, 5));
        $end = $start->copy()->addMinutes(max(1, $minutes));

        return Appointment::with(['branch', 'services.serviceVariant'])
            ->whereHas('client', fn ($q) => $q->where('user_id', $user->id))
            ->whereDate('appointment_date', $date)
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value, AppointmentStatus::Completed->value])
            ->when($exceptAppointmentId, fn ($q) => $q->where('id', '!=', $exceptAppointmentId))
            ->get()
            ->first(function (Appointment $other) use ($date, $start, $end) {
                $from = Carbon::parse($date.' '.substr((string) $other->appointment_time, 0, 5));
                $to = $from->copy()->addMinutes(max(1, $this->availabilityService->estimatedDurationMinutes($other)));

                return $start->lt($to) && $end->gt($from);
            });
    }

    // What a client is told when the time runs over a booking they already have.
    public function clientClashMessage(Appointment $other): string
    {
        $from = Carbon::parse($other->appointment_date->toDateString().' '.substr((string) $other->appointment_time, 0, 5));
        $to = $from->copy()->addMinutes(max(1, $this->availabilityService->estimatedDurationMinutes($other)));
        $where = $other->branch?->branch_name ? " at {$other->branch->branch_name}" : '';

        return "You already have a booking from {$from->format('g:i A')} to {$to->format('g:i A')} that day{$where}. Pick a time after it ends or another day.";
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
                    .($reason ? ' Reason: '.rtrim($reason, '. ').'.' : '')
                    .' You can book again anytime in the app.',
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
        if (! $appointment->canTransitionTo(AppointmentStatus::Cancelled->value)) {
            return response()->json(['message' => 'This appointment can no longer be cancelled.'], 422);
        }

        $blocked = $appointment->services()->whereIn('status', [AppointmentServiceStatus::InProgress->value, AppointmentServiceStatus::Completed->value])->exists();
        if ($blocked) {
            return response()->json(['message' => 'Cannot cancel an appointment with a service already in progress or completed.'], 422);
        }

        $serviceIds = $appointment->services()->pluck('id');

        // Bulk query-builder update, not individual model saves — doesn't
        // fire AppointmentServiceItem's model events, so the totals
        // recalculation below can't be skipped in favor of relying on
        // that safety net alone (see AppointmentServiceItem::booted()).
        $appointment->services()->where('status', AppointmentServiceStatus::Pending->value)->update(['status' => AppointmentServiceStatus::Cancelled->value]);

        TherapistAssignment::whereIn('appointment_service_id', $serviceIds)
            ->where('assignment_status', AssignmentStatus::Assigned->value)
            ->update(['assignment_status' => AssignmentStatus::Cancelled->value]);

        if ($appointment->queue) {
            $this->queueRepository->updateStatus($appointment->queue, QueueStatus::Cancelled->value);
        }

        $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

        $appointment->update([
            'status' => AppointmentStatus::Cancelled->value,
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
                    .Carbon::parse("{$payload['appointment_date']} {$payload['appointment_time']}")->format('D, M j \\a\\t g:i A').'.',
            );

            return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
        });
    }

    // The cancellation-window rule (and, for a move, the booking window of the
    // new time) for the front desk — a refusal to return, or what to keep.
    private function lateChangeOverrides(PolicyGate $gate, User $user, Appointment $appointment, string $action, array $payload = [])
    {
        if (! $appointment->branch || $appointment->status !== AppointmentStatus::Scheduled->value) {
            return [];
        }

        $policy = BookingPolicy::for($appointment->branch);
        $now = BookingPolicy::now();
        $startsAt = Carbon::parse($appointment->appointment_date->format('Y-m-d').' '.$appointment->appointment_time, 'Asia/Manila');
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
        $package = ! empty($payload['package_uuid']) ? (int) (Package::where('uuid', $payload['package_uuid'])->with(Package::contents())->first()?->totalMinutes(HomeServiceRules::isHome($payload)) ?? 0) : 0;
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
        if (! in_array($appointment->status, [AppointmentStatus::Scheduled->value, AppointmentStatus::CheckedIn->value], true)) {
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
        $wasCheckedIn = $appointment->status === AppointmentStatus::CheckedIn->value;

        if ($wasCheckedIn && $appointment->queue) {
            $this->queueRepository->updateStatus($appointment->queue, QueueStatus::Cancelled->value);
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
            'status' => $wasCheckedIn ? AppointmentStatus::Scheduled->value : $appointment->status,
            'check_in_at' => $wasCheckedIn ? null : $appointment->check_in_at,
            'appointment_type' => $becomesReservation ? 'Reservation' : $appointment->appointment_type,
        ]);

        return null;
    }

    // The wallet voucher a client booked with, or a 422 saying why it can't be
    // used: not theirs, used or expired, already on another booking, or for
    // services this booking doesn't have (VoucherTarget::fits).
    private function voucherForBooking(Client $client, ?string $voucherUuid, array $serviceItems, ?string $firstVisitProgramUuid = null, ?int $branchId = null): ClientVoucher
    {
        $voucher = $voucherUuid ? ClientVoucher::with('program')->where('uuid', $voucherUuid)->where('client_id', $client->id)->lockForUpdate()->first() : null;
        // A first-visit voucher not claimed yet: given to the client now (only
        // while they have no paid visit), then used like any other.
        if (! $voucher && $firstVisitProgramUuid) {
            $firstVisit = CustomerProgram::where('uuid', $firstVisitProgramUuid)->where('spa_business_id', $client->spa_business_id)->first();
            $voucher = $firstVisit ? app(ProgramRewardService::class)->claimFirstVisit($client, $firstVisit, ProgramChannel::APP, $branchId) : null;
            throw_if(! $voucher, ActionRefused::class, "That first-visit voucher isn't available to you any more.");
            $voucher->load('program');
        }
        $program = $voucher?->dealProgram();
        throw_if(! $voucher || ! $program, ActionRefused::class, "That voucher isn't in your wallet.");
        throw_if(! app(ProgramRewardService::class)->voucherAllowedAt($voucher, ProgramChannel::APP), ActionRefused::class, "That voucher can't be used on the app. ".ProgramChannel::refusalAt(ProgramChannel::APP));
        throw_if(! $voucher->isUsable(), ActionRefused::class, $voucher->status === VoucherStatus::Used->value ? 'That voucher has already been used.' : 'That voucher has expired.');
        throw_if(ClientVoucher::heldIds([$voucher->id]) !== [], ActionRefused::class, 'That voucher is already on another booking.');

        $describe = function ($item) {
            $item->loadMissing('serviceVariant.service');

            return ['uuid' => $item->serviceVariant?->service?->uuid, 'category' => $item->category(), 'name' => $item->displayName()];
        };
        $services = collect($serviceItems)->map($describe)->all();

        throw_if(! VoucherTarget::fits(
            (string) $program->value('dealType', '₱ off'),
            VoucherTarget::of($program->values, 'usable'),
            (string) $program->value('usableOn', ''),
            $services,
        ), ActionRefused::class, 'This voucher is for '.$program->value('usableOn', 'certain services').'. Add one to your booking to use it.');

        return $voucher;
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

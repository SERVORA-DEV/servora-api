<?php

namespace App\Service\Business;

use App\Enums\AppointmentServiceStatus;
use App\Enums\AppointmentStatus;
use App\Enums\AssignmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\QueueStatus;
use App\Enums\StaffStatus;
use App\Exceptions\ActionRefused;
use App\Http\Resources\AppointmentResource;
use App\Models\Addon;
use App\Models\Appointment;
use App\Models\AppointmentPackage;
use App\Models\AppointmentServiceItem;
use App\Models\BranchPackage;
use App\Models\BranchService;
use App\Models\Package;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Repository\BillingRepository;
use App\Repository\Business\AppointmentRepository;
use App\Repository\Business\AppointmentServiceRepository;
use App\Repository\Business\QueueRepository;
use App\Repository\SpaBusinessRepository;
use App\Support\AppCache;
use App\Support\Activity\History;
use App\Support\Catalog\PackageShare;
use App\Support\Catalog\ServiceAddon;
use App\Support\Catalog\ServiceKind;
use App\Support\Catalog\ServiceParts;
use App\Support\Visits\ServiceVoidRules;
use App\Support\Visits\StaleVisit;
use Illuminate\Support\Facades\DB;

/**
 * What is on a visit: adding and removing services and packages, add-ons on a service, and
 * voiding a service that was already started or done.
 *
 * Split out of AppointmentService (which still hands every call on, for its older callers).
 */
class VisitItemsService
{
    public function __construct(
        private AppointmentRepository $appointmentRepository,
        private AppointmentServiceRepository $appointmentServiceRepository,
        private QueueRepository $queueRepository,
        private SpaBusinessRepository $spaBusinessRepository,
        private AppointmentAvailabilityService $availabilityService,
        private BillingRepository $billingRepository,
        private ServiceExecutionService $execution,
    ) {}

    // Anything added to a home visit later has to be doable at home too.
    // A refusal (ActionRefused and the rest) rolls the surrounding transaction back.
    private function assertHomeEligible(Appointment $appointment): void
    {
        if ($appointment->isHomeService() && ($blocked = HomeServiceRules::ineligibleServices($appointment))) {
            throw new ActionRefused(HomeServiceRules::ineligibleMessage($blocked));
        }
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

            if ($stale = StaleVisit::refusal($appointment)) {
                return $stale;
            }

            if (in_array($appointment->status, [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value], true)) {
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
                throw new ActionRefused($late);
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
        if ($queue && $queue->queue_status === QueueStatus::Completed->value) {
            $this->queueRepository->updateStatus($queue, QueueStatus::Serving->value, ['completed_at' => null]);
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

            if ($service->status !== AppointmentServiceStatus::Pending->value) {
                return response()->json(['message' => 'Only a service that has not started can be removed.'], 422);
            }
            // A package is sold whole: what came with it can't be taken off one by one.
            if ($service->source_appointment_package_id) {
                return response()->json(['message' => ($service->displayName() ?? 'This service').' comes with the package and can\'t be removed on its own.'], 422);
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
    // (App\Support\Catalog\ServiceAddon) — while the service is waiting or in
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

            if ($stale = StaleVisit::refusal($appointment)) {
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
                // Its own copy (App\Support\Activity\History).
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
            if ($main->status === AppointmentServiceStatus::InProgress->value) {
                $this->execution->rideWith($line, $main, now());
            }
            // Nothing added keeps a visit going past closing time.
            if ($late = $this->availabilityService->finishesPastClosing($appointment)) {
                throw new ActionRefused($late);
            }
            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    // Takes a service that has already started or finished off the visit —
    // added by mistake, the client changed their mind part-way, it had to be
    // stopped. Unlike removeService() above (not started yet, no questions
    // asked) this needs a reason, which is kept on the line with who did it,
    // and it is only allowed while nothing has been settled against it
    // (App\Support\Visits\ServiceVoidRules).
    //
    // What it does: the line and its therapist/room assignments are marked
    // Cancelled (never deleted) — so it is no longer charged, the therapist
    // earns no commission for it, and the therapist and room are free again.
    // A service that came with a package takes its share of the package
    // price with it (App\Support\Catalog\PackageShare) and the rest of the package
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
            if (in_array($service->status, [AppointmentServiceStatus::InProgress->value, AppointmentServiceStatus::Completed->value], true)) {
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
                ? $appointment->services->where('source_appointment_package_id', $package->id)->where('status', '!=', AppointmentServiceStatus::Cancelled->value)
                : collect();
            // Its add-ons go with it (App\Support\Catalog\ServiceAddon).
            $addons = $appointment->services
                ->where('status', '!=', AppointmentServiceStatus::Cancelled->value)
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
            $left = $appointment->services->where('status', '!=', AppointmentServiceStatus::Cancelled->value)->whereNotIn('id', $going);
            $leftCharge = $left->whereNull('source_appointment_package_id')->sum(fn ($s) => (float) $s->subtotal - (float) $s->discount_amount)
                + $left->whereNotNull('source_appointment_package_id')->sum(fn ($s) => (float) ($s->extra_charge ?? 0))
                + $appointment->packages->where('status', '!=', AppointmentServiceStatus::Cancelled->value)->sum(fn ($p) => (float) $p->subtotal - (float) $p->discount_amount - ($p->id === $package?->id ? $share : 0));

            $bill = $this->billingRepository->findForAppointment($appointment->id);
            $problem = ServiceVoidRules::problem(
                $service->status,
                $appointment->status,
                $bill ? [
                    'status' => $bill->status,
                    'kept' => (float) $bill->payments->where('payment_status', PaymentStatus::Paid->value)->sum(fn ($p) => (float) $p->amount - (float) $p->refunded_amount),
                    'discount' => (float) $bill->discount_amount,
                    'credit' => (float) ($bill->credit_amount ?? 0),
                ] : null,
                $leftCharge <= 0,
            );
            if ($problem) {
                return response()->json(['message' => $problem], 422);
            }

            $by = $user->displayName();

            foreach ($line->therapistAssignments->whereIn('assignment_status', [AssignmentStatus::Assigned->value, AssignmentStatus::InProgress->value, AssignmentStatus::Completed->value]) as $assignment) {
                $assignment->update(['assignment_status' => AssignmentStatus::Cancelled->value]);
            }
            $reason = $payload['reason'].($package
                ? ' — ₱'.number_format($share, 2).' off '.($package->displayName() ?? 'the package')
                : '');
            $line->update([
                'status' => AppointmentServiceStatus::Cancelled->value,
                'notes' => ServiceVoidRules::note($reason, $by, $line->notes),
            ]);
            foreach ($addons as $addon) {
                foreach ($addon->therapistAssignments->whereIn('assignment_status', [AssignmentStatus::Assigned->value, AssignmentStatus::InProgress->value, AssignmentStatus::Completed->value]) as $assignment) {
                    $assignment->update(['assignment_status' => AssignmentStatus::Cancelled->value]);
                }
                $addon->update([
                    'status' => AppointmentServiceStatus::Cancelled->value,
                    'notes' => ServiceVoidRules::note($payload['reason'], $by, $addon->notes),
                ]);
            }
            $package?->update(
                ['subtotal' => round(max(0, (float) $package->subtotal - $share), 2)]
                + ($packageEnds ? ['status' => AppointmentServiceStatus::Cancelled->value] : [])
            );

            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);
            $appointment->refresh();

            // The bill was made from the old total (no payment, no discount on
            // it — the rules above saw to that), so it simply follows.
            if ($bill) {
                $bill->update(['subtotal' => $appointment->total_amount, 'amount' => $appointment->total_amount]);
            }

            // Where that leaves the visit.
            $live = $appointment->services()->where('status', '!=', AppointmentServiceStatus::Cancelled->value)->get();
            $served = $live->whereIn('status', [AppointmentServiceStatus::InProgress->value, AppointmentServiceStatus::Completed->value])->isNotEmpty();
            $unfinished = $live->whereIn('status', [AppointmentServiceStatus::Pending->value, AppointmentServiceStatus::InProgress->value])->isNotEmpty();
            $queue = $appointment->queue;

            if (! $served && ! $bill && $appointment->status === AppointmentStatus::InService->value) {
                // Nothing has been served any more: the client is checked in
                // and waiting again, so the visit can carry on or be cancelled.
                $appointment->update(['status' => AppointmentStatus::CheckedIn->value]);
                if ($queue && in_array($queue->queue_status, [QueueStatus::Serving->value, QueueStatus::Completed->value], true)) {
                    $this->queueRepository->updateStatus($queue, QueueStatus::Waiting->value, ['served_at' => null, 'completed_at' => null]);
                }
            } elseif ($live->isNotEmpty() && ! $unfinished && $queue && $queue->queue_status !== QueueStatus::Completed->value) {
                // What is left is all done: the ticket is finished, as when the
                // last service is completed.
                $this->queueRepository->updateStatus($queue, QueueStatus::Completed->value, ['completed_at' => now()]);
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

            if ($stale = StaleVisit::refusal($appointment)) {
                return $stale;
            }

            if (in_array($appointment->status, [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value], true)) {
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
                throw new ActionRefused($late);
            }

            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);
            $this->reopenForMore($appointment);

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointmentUuid));
        });
    }

    // ── Internal helpers ─────────────────────────────────────────────────

    // Aborts the surrounding transaction (422) if the package isn't bookable
    // at the appointment's branch — same hard-integrity stance as
    // addServiceInternal(). See addPackage() for why packages explode into
    // per-component service lines.
    // $openWork: added to a visit that is already open, which may be finished just after the plan ends.
    // $sharedFrom: Client B (C…) joining the package Client A bought (SharedPackages): this
    // appointment gets that client's services and no price; Client A paid for it.
    public function addPackageInternal(Appointment $appointment, int $businessId, string $packageUuid, int $quantity, bool $openWork = false, ?AppointmentPackage $sharedFrom = null): AppointmentPackage
    {
        // Every way a package gets onto an appointment (a client's booking,
        // the front desk) passes here: the plan has to include packages.
        if (! ($openWork ? AppCache::planAllowsOpenWork($businessId, 'package_access') : AppCache::planAllows($businessId, 'package_access'))) {
            throw new ActionRefused('Packages aren\'t available at this spa right now. Please choose services instead.');
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
            throw new ActionRefused('This package is not available at this branch.');
        }

        $home = $appointment->isHomeService();
        if ($home && ! $package->homeBookable()) {
            throw new ActionRefused('Review this package’s home-service lengths and price before booking it.');
        }

        // Charged as a whole, so every service in it has to still exist: one removed
        // from the menu would otherwise be skipped and the package sold short.
        foreach ($package->packageServiceItems as $item) {
            if (! $item->guideVariant($home)) {
                throw new ActionRefused('This package can\'t be booked right now: '.($item->service?->name ?? 'one of its services').' is no longer on the menu.');
            }
        }

        $price = $home ? (float) ($branchPackage->home_custom_price ?? $package->home_price) : (float) ($branchPackage->custom_price ?? $package->default_price);

        // A package for several clients (a Couples Massage): Client A books and pays for it, and
        // holds the staff for the others until they are added (SharedPackages). This appointment
        // gets only its own client's services.
        $clients = $package->clients();
        $slot = 1;
        if ($sharedFrom) {
            $slot = \App\Support\Catalog\SharedPackages::nextSlot($sharedFrom) ?? throw new ActionRefused("{$package->name} already has all its {$clients} clients.");
            [$price, $quantity] = [0.0, 1];
        } elseif ($clients > 1) {
            $quantity = 1;
            $staff = \App\Models\Staff::where('spa_branch_id', $appointment->spa_branch_id)->where('role', 'therapist')->where('status', StaffStatus::Active->value)->count();
            throw_if($staff < $clients, ActionRefused::class, "{$package->name} is for {$clients} clients, each with their own staff, and only {$staff} work at this branch.");
        }

        $appointmentPackage = AppointmentPackage::create([
            'appointment_id' => $appointment->id,
            'package_id' => $package->id,
            'quantity' => $quantity,
            'unit_price' => $price,
            'subtotal' => $price * $quantity,
        ] + ($clients > 1 ? ['client_slot' => $slot, 'shared_from_id' => $sharedFrom?->id] : []));

        $nextSort = (int) ($appointment->services()->max('sort_order') ?? 0);
        // The line made for each of the package's services, for hanging its
        // included add-ons on.
        $lineOf = [];

        foreach ($package->packageServiceItems as $index => $item) {
            // Several clients: only what this client gets.
            if ($clients > 1 && ! $item->isFor($slot)) {
                continue;
            }
            // A package names the service and gives it its own minutes
            // (App\Support\Catalog\PackagePlan). The line still points at the menu
            // length nearest those minutes — only so the rest of the visit
            // knows which service it is (therapist skills, rooms, extending)
            // and what it lists for; the minutes the client gets are the
            // package's, kept on the line below.
            $variant = $item->guideVariant($home);
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
            // the package (App\Support\Catalog\PackageShare), not an extra charge.
            $componentPrice = $home ? (float) ($item->home_price ?? $variant->price) : (Package::hasLinePrice() && $item->price !== null
                ? (float) $item->price
                : (float) ($branchService?->custom_price ?? $variant->price));
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
            // was just given (App\Support\Activity\History keeps the line's own).
            if (History::ready() && $item->minutes($home) && (int) $line->duration_minutes !== $item->minutes($home)) {
                $line->update(['duration_minutes' => $item->minutes($home)]);
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

    // Aborts the surrounding transaction (422) if the variant isn't
    // bookable at this branch at all — a hard data-integrity condition, not
    // a soft business-rule branch like the availability checks below.
    public function addServiceInternal(Appointment $appointment, int $branchId, array $item, ?int $sourcePackageId, int $sortOrder): AppointmentServiceItem
    {
        $variant = ServiceVariant::where('uuid', $item['service_variant_uuid'])->firstOrFail();
        $location = $appointment->isHomeService() ? HomeServiceRules::HOME : HomeServiceRules::IN_SPA;
        if (($variant->service_location ?? HomeServiceRules::IN_SPA) !== $location) {
            throw new ActionRefused('Choose a length and price for the selected booking location.');
        }
        // A home visit has no facility, so "Facility required" (a spa matter) does not stop it.
        if ($appointment->isHomeService() && ! $variant->service?->home_service_available) {
            throw new ActionRefused('This service is not available for home service.');
        }

        // Done by several staff at once: the branch must have that many who can do it.
        if ($shortage = ServiceParts::shortage($branchId, $variant)) {
            throw new ActionRefused($shortage);
        }

        $branchService = BranchService::where('service_variant_id', $variant->id)
            ->where('spa_branch_id', $branchId)
            ->where('is_available', true)
            ->first();

        if (! $branchService) {
            throw new ActionRefused('This service is not available at the selected branch.');
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
                ->where('status', AppointmentServiceStatus::Cancelled->value)
                ->first();

            if ($existingCancelled) {
                $existingCancelled->update([
                    'status' => AppointmentServiceStatus::Pending->value,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'discount_amount' => $item['discount_amount'] ?? 0,
                    'subtotal' => $price * $quantity,
                    'points_earned' => 0,
                    'notes' => $item['notes'] ?? null,
                    'sort_order' => $sortOrder,
                    // Booked anew: the saving hook takes a fresh copy of the
                    // name and length (App\Support\Activity\History).
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

<?php

namespace App\Service\Business;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\BranchService;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Repository\BillingRepository;
use App\Repository\Business\AppointmentRepository;
use App\Repository\Business\AppointmentServiceRepository;
use App\Repository\SpaBusinessRepository;
use App\Service\Client\BookingPolicy;
use App\Support\BookingBoard;
use App\Support\FrontDeskPolicy;
use App\Support\ServiceExtension;
use App\Support\ServiceKind;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Extending a service that is in progress: the client asks for longer, so the
// line moves to a longer length of the same service and is charged that
// length's price (App\Support\ServiceExtension holds the rules). The therapist
// and the room stay; the visit's total, the therapist's commission, the
// "should end" time and the front desk's "time is up" notice all follow from
// the line's length and price, so nothing else has to be told. A service that
// came with a package keeps the package's price and is charged only the
// difference, on top (extra_charge).
//
// Who else is booked with that therapist or in that room before the new end
// is given back as a warning with each choice — the desk decides.
class ServiceExtensionService
{
    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private AppointmentRepository $appointmentRepository,
        private AppointmentServiceRepository $appointmentServiceRepository,
        private BillingRepository $billingRepository,
    ) {
    }

    /** The longer lengths this service can be extended to, with what each adds and who it would keep waiting. */
    public function options(User $user, string $appointmentServiceUuid)
    {
        $service = $this->find($user, $appointmentServiceUuid);
        $appointment = $service->appointment;

        // An add-on is not a length of a service: there is nothing longer to move it to.
        if ($service->isAddon()) {
            return ['data' => ['can_extend' => false, 'reason' => 'An add-on rides with its main service — extend the main service, or add another add-on.', 'options' => []]];
        }

        $problem = ServiceExtension::problem(
            $service->status,
            $service->source_appointment_package_id !== null,
            $this->billingRepository->findForAppointment($appointment->id) !== null,
            $service->kind(),
            Service::hasTypes(),
        );

        // The owner's Service Extension settings (FrontDeskPolicy): switched
        // off means not offered at all; the longest extension and "needs
        // approval" mark a choice as needing an override.
        $policy = $appointment->branch ? BookingPolicy::for($appointment->branch) : null;
        $already = (int) ($service->extended_minutes ?? 0);
        if (! $problem && $policy) {
            $problem = collect(FrontDeskPolicy::extension($policy, $already, 0))->firstWhere('overridable', false)['message'] ?? null;
        }

        $currentMinutes = (int) ($service->lengthMinutes() ?? 0);
        $startedAt = $this->startedAt($service);
        $options = $problem ? [] : ServiceExtension::options($currentMinutes, (float) $service->unit_price, $this->lengthsOffered($service));

        $running = $service->therapistAssignments->where('assignment_status', 'In Progress');
        $staff = $running->filter(fn ($a) => $a->staff)->mapWithKeys(fn ($a) => [$a->staff_id => trim("{$a->staff->first_name} {$a->staff->last_name}")])->all();
        $rooms = $running->filter(fn ($a) => $a->facility)->mapWithKeys(fn ($a) => [$a->facility_id => $a->facility->name])->all();
        $bookings = $options && $startedAt ? $this->othersWaiting($appointment) : [];
        $now = now();

        // Its add-ons ride with it: every end time below is that much later.
        $added = \App\Support\ServiceAddon::addedMinutes($service->liveAddons()->map(fn ($a) => $a->lengthMinutes())->all());

        return ['data' => [
            'can_extend' => $problem === null && $options !== [],
            'reason' => $problem,
            'duration_minutes' => $currentMinutes,
            'started_at' => $startedAt?->toIso8601String(),
            'due_at' => $startedAt && $currentMinutes ? (clone $startedAt)->addMinutes($currentMinutes + $added)->toIso8601String() : null,
            // Whether this person may go ahead on a choice that needs an override.
            'can_override' => app(PolicyGate::class)->mayOverride($user),
            'options' => array_map(function (array $option) use ($startedAt, $now, $staff, $rooms, $bookings, $policy, $already, $added) {
                $endsAt = $startedAt ? (clone $startedAt)->addMinutes($option['minutes'] + $added) : null;
                // A new end past midnight still counts as later than everything today.
                $newEnd = $endsAt ? ($endsAt->isSameDay($now) ? $endsAt->hour * 60 + $endsAt->minute : 24 * 60) : 0;
                $clashes = $endsAt
                    ? ServiceExtension::clashes($newEnd, $now->hour * 60 + $now->minute, array_keys($staff), array_keys($rooms), $bookings)
                    : [];

                return [
                    'service_variant_uuid' => $option['uuid'],
                    'duration_minutes' => $option['minutes'],
                    'price' => $option['price'],
                    'extra_minutes' => $option['extra_minutes'],
                    'extra_price' => $option['extra_price'],
                    'ends_at' => $endsAt?->toIso8601String(),
                    // The rules this choice breaks (it then needs an override).
                    'policy' => $policy ? array_column(FrontDeskPolicy::extension($policy, $already, $option['extra_minutes']), 'message') : [],
                    'warnings' => array_values(array_unique(array_map(function (array $clash) use ($staff, $rooms) {
                        $who = $clash['kind'] === 'therapist' ? ($staff[$clash['id']] ?? 'The staff member') : ($rooms[$clash['id']] ?? 'The room');
                        $at = Carbon::today()->addMinutes($clash['start'])->format('g:i A');

                        return match (true) {
                            $clash['kind'] === 'room' => "{$who} is booked for {$clash['client']} at {$at}.",
                            $clash['waiting'] => "{$clash['client']} is already waiting for {$who} (since {$at}).",
                            default => "{$who} has {$clash['client']} at {$at}.",
                        };
                    }, $clashes))),
                ];
            }, $options),
        ]];
    }

    public function extend(User $user, string $appointmentServiceUuid, array $payload)
    {
        return DB::transaction(function () use ($user, $appointmentServiceUuid, $payload) {
            $service = $this->find($user, $appointmentServiceUuid, lock: true);
            $appointment = Appointment::whereKey($service->appointment_id)->lockForUpdate()->firstOrFail();

            if ($service->isAddon()) {
                return response()->json(['message' => 'An add-on rides with its main service — extend the main service, or add another add-on.'], 422);
            }

            $fromPackage = $service->source_appointment_package_id !== null;
            $problem = ServiceExtension::problem(
                $service->status,
                $fromPackage,
                $this->billingRepository->findForAppointment($appointment->id) !== null,
                $service->kind(),
                Service::hasTypes(),
            );
            if ($problem) {
                return response()->json(['message' => $problem], 422);
            }

            $current = $service->serviceVariant;
            $currentMinutes = (int) ($service->lengthMinutes() ?? 0);
            $target = ServiceVariant::where('uuid', $payload['service_variant_uuid'])->first();
            $offered = $target
                ? BranchService::where('service_variant_id', $target->id)->where('spa_branch_id', $appointment->spa_branch_id)->where('is_available', true)->first()
                : null;

            $problem = ServiceExtension::targetProblem($target ? [
                'same_service' => $current && $target->service_id === $current->service_id,
                'minutes' => (int) $target->duration_minutes,
                'offered' => $offered !== null,
            ] : null, $currentMinutes);
            if ($problem) {
                return response()->json(['message' => $problem], 422);
            }

            // The owner's extension settings: refused unless overridden with
            // a reason (PolicyGate) — kept on the booking below.
            $extra = (int) $target->duration_minutes - $currentMinutes;
            $gate = app(PolicyGate::class);
            $overrides = $appointment->branch
                ? $gate->check($user, FrontDeskPolicy::extension(BookingPolicy::for($appointment->branch), (int) ($service->extended_minutes ?? 0), $extra))
                : [];
            if (! is_array($overrides)) {
                return $overrides;
            }

            $staff = $user->staff;
            $by = ($staff ? trim("{$staff->first_name} {$staff->last_name}") : '')
                ?: (trim("{$user->first_name} {$user->last_name}") ?: 'the front desk');
            $price = (float) ($offered->custom_price ?? $target->price);
            $quantity = max(1, (int) $service->quantity);

            // A package keeps its price: only the difference between the two
            // lengths is charged, on top (extra_charge). A service on its own
            // is simply charged the longer length.
            $onTop = $fromPackage ? round(max(0, ($price - (float) $service->unit_price) * $quantity), 2) : null;

            $service->fill([
                'service_variant_id' => $target->id,
                'unit_price' => $price,
                'subtotal' => $price * $quantity,
                'notes' => ServiceExtension::note($currentMinutes, (int) $target->duration_minutes, $by, $service->notes, $onTop),
            ] + ($fromPackage ? ['extra_charge' => round((float) ($service->extra_charge ?? 0) + $onTop, 2)] : [])
              + (PolicyGate::ready() ? ['extended_minutes' => (int) ($service->extended_minutes ?? 0) + $extra] : []));
            // The line is now this length: its own copy follows (History).
            if (\App\Support\History::ready()) {
                $service->copyFromMenu();
            }
            $service->save();
            $gate->record($appointment, $overrides, $user);
            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    private function find(User $user, string $uuid, bool $lock = false): AppointmentServiceItem
    {
        $branchIds = $this->spaBusinessRepository->branchIdsForUser($user);

        return AppointmentServiceItem::whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
            ->where('uuid', $uuid)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->with(['serviceVariant.service', 'appointment', 'therapistAssignments.staff:id,first_name,last_name', 'therapistAssignments.facility:id,name'])
            ->firstOrFail();
    }

    private function startedAt(AppointmentServiceItem $service): ?Carbon
    {
        $first = $service->therapistAssignments->where('assignment_status', 'In Progress')->whereNotNull('started_at')->min('started_at');

        return $first ? Carbon::parse($first) : null;
    }

    /** Every length of this line's service offered at its branch, at the branch's price. */
    private function lengthsOffered(AppointmentServiceItem $service): array
    {
        $serviceId = $service->serviceVariant?->service_id;
        if (! $serviceId) {
            return [];
        }

        return BranchService::where('spa_branch_id', $service->appointment->spa_branch_id)
            ->where('is_available', true)
            ->whereHas('serviceVariant', fn ($q) => $q->where('service_id', $serviceId))
            ->with('serviceVariant')
            ->get()
            ->map(fn (BranchService $b) => [
                'uuid' => $b->serviceVariant->uuid,
                'minutes' => (int) $b->serviceVariant->duration_minutes,
                'price' => (float) ($b->custom_price ?? $b->serviceVariant->price),
            ])
            ->values()->all();
    }

    // The day's other open bookings that are not being served yet, each with
    // the therapists it is waiting for (assigned, or — while nobody is — the
    // one it asked for) and the rooms held for it.
    private function othersWaiting(Appointment $appointment): array
    {
        $remembers = Appointment::remembersRequests();

        return Appointment::where('spa_branch_id', $appointment->spa_branch_id)
            ->whereDate('appointment_date', now()->toDateString())
            ->whereKeyNot($appointment->id)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW, Appointment::STATUS_COMPLETED])
            ->with(['client:id,first_name,last_name', 'services.therapistAssignments'])
            ->get()
            ->map(function (Appointment $a) use ($remembers) {
                $waiting = $a->services->where('status', 'Pending')
                    ->flatMap(fn ($s) => $s->therapistAssignments->where('assignment_status', 'Assigned'));
                $staff = $waiting->pluck('staff_id')->filter()->unique()->values()->all();
                $anyoneAssigned = $a->services->flatMap(fn ($s) => $s->therapistAssignments->whereIn('assignment_status', ['Assigned', 'In Progress', 'Completed']))->isNotEmpty();
                if (! $staff && ! $anyoneAssigned && $remembers && $a->requested_staff_id) {
                    $staff = [(int) $a->requested_staff_id];
                }

                return [
                    'start' => BookingBoard::minutes((string) $a->appointment_time),
                    'client' => trim(($a->client?->first_name ?? '') . ' ' . ($a->client?->last_name ?? '')) ?: 'a client',
                    'staff' => array_map('intval', $staff),
                    'rooms' => array_map('intval', $waiting->pluck('facility_id')->filter()->unique()->values()->all()),
                ];
            })
            ->filter(fn (array $b) => $b['staff'] || $b['rooms'])
            ->values()->all();
    }
}

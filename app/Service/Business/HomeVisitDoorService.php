<?php

namespace App\Service\Business;

use App\Models\Addon;
use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\Billing;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use App\Support\ServiceAddon;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

// The staff at the client's home, on the branch's scanner page (ScannerController): the visit
// found by its code, then step by step — its details, Start, the countdown with Extend,
// Service done, and Checkout, where the payment is taken there and then.
//
// Nobody is signed in: the branch's link and the visit's code are the proof, the same as a
// scan has always been. So nothing here can override the owner's rules: an extension that
// needs approval (or goes past the longest allowed) is sent to the front desk instead, and
// the desk extends it from the booking as usual; the countdown picks the new end up.
//
// Every step answers with the same view of the visit (visit()), so the page can always show
// where it stands, whoever moved it on (the desk can start, extend or complete it too).
class HomeVisitDoorService
{
    // Taken at the door: cash, or an e-wallet with its reference. A card stays at the spa.
    public const DOOR_METHODS = ['Cash', 'GCash', 'Maya'];

    public function __construct(
        private AppointmentService $appointments,
        private ServiceExtensionService $extensions,
        private BillingService $billing,
        private StaffNotifier $staffNotifier,
    ) {
    }

    public function look(SpaBranch $branch, string $code): array
    {
        return DB::transaction(function () use ($branch, $code) {
            $appointment = $this->find($branch, $code);
            // Every service done (here or at the desk): the bill is ready to take.
            if ($this->state($appointment) === 'services_done') {
                $this->appointments->billHomeVisit($appointment);
            }

            return $this->visit($appointment);
        });
    }

    public function start(SpaBranch $branch, string $code): array
    {
        return DB::transaction(function () use ($branch, $code) {
            $appointment = $this->find($branch, $code);
            $this->appointments->startHomeVisitAtDoor($appointment, $branch);

            return $this->visit($appointment, 'Service started.');
        });
    }

    public function extendOptions(SpaBranch $branch, string $code, string $serviceUuid): array
    {
        return DB::transaction(function () use ($branch, $code, $serviceUuid) {
            $line = $this->line($this->find($branch, $code), $serviceUuid);
            $data = $this->extensions->optionsFor($line, null)['data'];

            // What the staff can do with each: add it now, or ask the desk.
            $data['options'] = array_map(fn (array $o) => $o + ['needs_desk' => $o['policy'] !== []], $data['options']);

            return $data;
        });
    }

    // Added at once when the spa's rules allow it; otherwise nothing changes and the desk is
    // asked (they extend it from the booking, with their override where needed).
    public function extend(SpaBranch $branch, string $code, array $payload): array
    {
        return DB::transaction(function () use ($branch, $code, $payload) {
            $appointment = $this->find($branch, $code);
            $line = $this->line($appointment, (string) $payload['service_uuid']);
            $by = $this->staffOn($line);

            $options = $this->extensions->optionsFor($line, null)['data'];
            abort_if(! $options['can_extend'], 422, $options['reason'] ?? 'This service can\'t be extended.');
            $chosen = collect($options['options'])->first(fn (array $o) => ! empty($payload['service_variant_uuid'])
                ? $o['kind'] === 'length' && $o['service_variant_uuid'] === $payload['service_variant_uuid']
                : $o['kind'] === 'time' && (int) $o['extra_minutes'] === (int) ($payload['extra_minutes'] ?? 0));
            abort_if(! $chosen, 422, 'That extension isn\'t offered for this service. Pick one of the choices shown.');

            $name = $line->displayName() ?: 'the service';
            if ($chosen['policy'] !== []) {
                $client = $appointment->client?->first_name ?: 'the client';
                $this->staffNotifier->appointment(
                    $appointment,
                    'Extension requested at a home visit',
                    "{$by} asks to extend {$name} for {$client} by {$chosen['extra_minutes']} min (home visit, booking no. {$appointment->appointment_number}). "
                        .implode(' ', $chosen['policy']).' Extend it from the booking to approve.',
                );

                return $this->visit($appointment, 'Sent to the front desk for approval. The time here updates once they extend it.', 'requested');
            }

            $result = $this->extensions->extendLine($line, array_filter([
                'extra_minutes' => $chosen['kind'] === 'time' ? (int) $chosen['extra_minutes'] : null,
                'service_variant_uuid' => $chosen['kind'] === 'length' ? $chosen['service_variant_uuid'] : null,
            ]), null, "{$by} at the client's home");
            $this->refuseOnError($result);

            return $this->visit($appointment, "Added {$chosen['extra_minutes']} min to {$name}.", 'extended');
        });
    }

    public function complete(SpaBranch $branch, string $code): array
    {
        return DB::transaction(function () use ($branch, $code) {
            $appointment = $this->find($branch, $code);
            $this->appointments->completeHomeVisitServices($appointment);
            if ($this->state($appointment) === 'services_done') {
                $this->appointments->billHomeVisit($appointment);

                return $this->visit($appointment, 'Service done. Take the payment to finish the visit.');
            }

            return $this->visit($appointment, 'Done. The next service has started.');
        });
    }

    public function checkout(SpaBranch $branch, string $code, array $payload): array
    {
        return DB::transaction(function () use ($branch, $code, $payload) {
            $appointment = $this->find($branch, $code);
            abort_if(! in_array($payload['payment_method'], self::DOOR_METHODS, true), 422,
                'At the client\'s home, take cash, GCash or Maya. A card payment is taken at the spa.');
            $state = $this->state($appointment);
            abort_if($state === 'paid', 422, 'This visit is already paid.');
            abort_if($state !== 'services_done', 422, 'Finish the services first (Service done), then check out.');

            $billing = $this->appointments->billHomeVisit($appointment);
            $receipt = $this->appointments->payHomeVisit($appointment, $billing, $payload, $this->staffOf($appointment));
            $paid = $receipt['billing_status'] === 'Paid';

            return $this->visit(
                $appointment,
                $paid ? 'Payment received. The visit is complete.' : 'Payment received. ₱'.number_format($receipt['balance'], 2).' is still due.',
            ) + ['receipt' => $receipt];
        });
    }

    // ── The visit as the page shows it ──────────────────────────────────

    public function visit(Appointment $appointment, ?string $notice = null, ?string $event = null): array
    {
        $appointment = Appointment::with([
            'client:id,first_name',
            'branch.business.settings',
            'services' => fn ($q) => $q->where('status', '!=', 'Cancelled')->orderBy('sort_order')->orderBy('id'),
            'services.serviceVariant.service',
            'services.therapistAssignments.staff:id,first_name,last_name',
        ])->findOrFail($appointment->id);

        $all = $appointment->services;
        // Add-ons ride with their service: their minutes are its.
        $main = Addon::ready() ? $all->whereNull('parent_appointment_service_id') : $all;
        $services = $main->map(function (AppointmentServiceItem $line) use ($all) {
            $addons = $all->where('parent_appointment_service_id', $line->id);
            $minutes = (int) ($line->lengthMinutes() ?? 0) + ServiceAddon::addedMinutes($addons->map(fn ($a) => $a->lengthMinutes())->all());
            $started = $line->therapistAssignments->whereNotNull('started_at')->min('started_at');
            $started = $started ? Carbon::parse($started) : null;

            return [
                'uuid' => $line->uuid,
                'name' => $line->displayName(),
                'addons' => $addons->map(fn ($a) => $a->displayName())->filter()->values()->all(),
                'minutes' => $minutes,
                'extended_minutes' => (int) ($line->extended_minutes ?? 0),
                'status' => $line->status,
                'started_at' => $started?->toIso8601String(),
                'ends_at' => $started && $minutes ? $started->copy()->addMinutes($minutes)->toIso8601String() : null,
            ];
        })->values();

        $running = $services->where('status', 'In Progress');
        $billing = Billing::with('payments')->where('appointment_id', $appointment->id)->where('billing_type', 'Appointment')->first();
        $paidTotal = $billing ? round((float) $billing->payments->where('payment_status', 'Paid')->sum('amount'), 2) : 0.0;
        $total = $billing ? (float) $billing->amount : (float) $appointment->total_amount;
        $last = $billing?->payments->where('payment_status', 'Paid')->sortByDesc('paid_at')->first();

        $business = $appointment->branch?->business;
        $settings = $business?->settings?->section('payments') ?? SpaBusinessSetting::DEFAULTS['payments'];

        return [
            'appointment_uuid' => $appointment->uuid,
            'booking_number' => $appointment->appointment_number,
            'client_first_name' => $appointment->client?->first_name,
            'address' => $appointment->home_address,
            'branch_name' => $appointment->branch?->branch_name,
            'state' => $this->state($appointment),
            'services' => $services->all(),
            'total_minutes' => (int) $services->sum('minutes'),
            'staff' => $all->flatMap(fn ($l) => $l->therapistAssignments->whereNotIn('assignment_status', ['Cancelled']))
                ->map(fn ($a) => trim("{$a->staff?->first_name} {$a->staff?->last_name}"))->filter()->unique()->values()->all(),
            // The page counts down on the server's clock (its own may be off).
            'server_now' => now()->toIso8601String(),
            'started_at' => $running->min('started_at'),
            'ends_at' => $running->max('ends_at'),
            'bill' => [
                'billing_number' => $billing?->billing_number,
                'status' => $billing?->status,
                'total' => round($total, 2),
                'paid' => $paidTotal,
                'balance' => $billing ? $this->billing->balance($billing) : round($total, 2),
            ],
            'payment_methods' => array_values(array_map(fn (string $m) => [
                'method' => $m,
                'requires_reference' => $this->billing->requiresReference($m),
                'account_number' => match ($m) {
                    'GCash' => $settings['gcash_number'] ?? null,
                    'Maya' => $settings['paymaya_number'] ?? null,
                    default => null,
                },
            ], array_intersect(self::DOOR_METHODS, $this->billing->enabledMethods($business instanceof SpaBusiness ? $business : null)))),
            'last_payment' => $last ? [
                'method' => $last->payment_method,
                'amount' => (float) $last->amount,
                'amount_tendered' => $last->amount_tendered !== null ? (float) $last->amount_tendered : null,
                'change' => $last->change_given !== null ? (float) $last->change_given : null,
                'paid_at' => $last->paid_at?->toIso8601String(),
            ] : null,
            'notice' => $notice,
            // 'extended' | 'requested' (an extension) | null.
            'event' => $event,
        ];
    }

    // not_started → in_service → services_done → paid.
    private function state(Appointment $appointment): string
    {
        $appointment = Appointment::find($appointment->id);
        if ($appointment->status === Appointment::STATUS_COMPLETED
            || Billing::where('appointment_id', $appointment->id)->where('billing_type', 'Appointment')->where('status', 'Paid')->exists()) {
            return 'paid';
        }
        $statuses = AppointmentServiceItem::where('appointment_id', $appointment->id)->where('status', '!=', 'Cancelled')->pluck('status');
        if ($statuses->contains('In Progress')) {
            return 'in_service';
        }
        if ($statuses->isNotEmpty() && ! $statuses->contains('Pending')) {
            return 'services_done';
        }

        return 'not_started';
    }

    // ── Finding things ──────────────────────────────────────────────────

    // Only $branch's own home visits, by the code on the client's booking.
    private function find(SpaBranch $branch, string $code): Appointment
    {
        $appointment = Appointment::where('spa_branch_id', $branch->id)
            ->where('home_start_code', $code)
            ->lockForUpdate()
            ->first();
        abort_if(! $appointment, 422, "This code isn't for a booking at {$branch->branch_name}. Check the code, or use the scanner of the branch the client booked with.");
        abort_if(! $appointment->isHomeService(), 422, 'This booking isn\'t a home visit.');
        abort_if(in_array($appointment->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW], true), 422,
            $appointment->status === Appointment::STATUS_CANCELLED ? 'This booking was cancelled.' : 'This booking was marked as a no-show. Call the front desk.');
        $appointment->setRelation('branch', $branch);

        return $appointment;
    }

    // A running service on this visit (only a running one can be extended).
    private function line(Appointment $appointment, string $uuid): AppointmentServiceItem
    {
        $line = AppointmentServiceItem::where('appointment_id', $appointment->id)->where('uuid', $uuid)
            ->with('therapistAssignments.staff:id,first_name,last_name')
            ->lockForUpdate()->first();
        abort_if(! $line, 422, 'That service isn\'t on this visit.');
        abort_if($line->status !== 'In Progress', 422, 'Only a service that is running can be extended.');
        $line->setRelation('appointment', $appointment);

        return $line;
    }

    private function staffOn(AppointmentServiceItem $line): string
    {
        $names = $line->therapistAssignments->whereIn('assignment_status', ['In Progress', 'Completed', 'Assigned'])
            ->map(fn ($a) => trim("{$a->staff?->first_name} {$a->staff?->last_name}"))->filter()->unique();

        return $names->isNotEmpty() ? $names->join(' and ') : 'The staff';
    }

    private function staffOf(Appointment $appointment): string
    {
        $names = AppointmentServiceItem::where('appointment_id', $appointment->id)->where('status', '!=', 'Cancelled')
            ->with('therapistAssignments.staff:id,first_name,last_name')->get()
            ->flatMap(fn ($l) => $l->therapistAssignments->where('assignment_status', 'Completed'))
            ->map(fn ($a) => trim("{$a->staff?->first_name} {$a->staff?->last_name}"))->filter()->unique();

        return $names->isNotEmpty() ? $names->join(' and ') : 'The staff';
    }

    // The extension service answers a refusal as a response; here it stops the step.
    private function refuseOnError($result): void
    {
        if ($result instanceof JsonResponse && $result->getStatusCode() >= 400) {
            abort($result->getStatusCode(), (string) ($result->getData(true)['message'] ?? 'This can\'t be done.'));
        }
    }
}

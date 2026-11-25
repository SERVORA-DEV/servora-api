<?php

namespace App\Service\Business;

use App\Enums\AppointmentServiceStatus;
use App\Enums\AppointmentStatus;
use App\Enums\AssignmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Billing;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// The file a plan with limited exports gets from Reports (PlanAccessController::export). It is the same
// report as the one on screen (useBusinessReports.ts), worked out on the server so the plan's date
// rule cannot be bypassed: the same words for a status, the same amounts (after discounts, net of
// refunds), cancelled bills left out, and "clients served", "services done" and "sales" counted from
// finished visits only.
class ReportExportService
{
    private const VISIT_STATUS = [
        'scheduled' => 'Booked', 'checked_in' => 'Checked in', 'in_service' => 'In service',
        'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No-show',
    ];

    /** @param Collection<int, \App\Models\SpaBranch> $branches the branches the person may read */
    public function table(Collection $branches, string $type, string $from, string $to, ?string $filter = null): array
    {
        return match ($type) {
            'sales' => $this->sales($branches, $from, $to, $filter),
            'appointments' => $this->appointments($branches, $from, $to, $filter),
            'clients' => $this->clients($branches, $from, $to, $filter),
            'services' => $this->services($branches, $from, $to, $filter),
            'branches' => $this->branches($branches, $from, $to),
            default => $this->staff($branches, $from, $to),
        };
    }

    private function column(string $label, string $kind = 'text', bool $total = false, ?int $decimals = null): array
    {
        return ['label' => $label, 'kind' => $kind, 'total' => $total] + ($decimals === null ? [] : ['decimals' => $decimals]);
    }

    private function value($v): string
    {
        return $v instanceof \BackedEnum ? (string) $v->value : (string) $v;
    }

    private function name($person): string
    {
        return $person ? trim($person->first_name.' '.$person->last_name) : '';
    }

    private function visits(Collection $branches, string $from, string $to): Collection
    {
        return Appointment::with(['client', 'branch', 'billing', 'services.therapistAssignments.staff'])
            ->whereIn('spa_branch_id', $branches->pluck('id'))->whereBetween('appointment_date', [$from, $to])
            ->orderBy('appointment_date')->orderBy('appointment_time')->get();
    }

    /** What a visit comes to: the bill once it is made, the quote before (discounts taken off). */
    private function total(Appointment $a): float
    {
        return (float) app(BillingService::class)->appointmentPricing($a)['total_amount'];
    }

    private function bills(Collection $branches, string $from, string $to): Collection
    {
        return Billing::with(['appointment.client', 'branch', 'payments'])
            ->whereIn('spa_branch_id', $branches->pluck('id'))->where('billing_type', 'Appointment')
            ->whereBetween('issued_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->where('status', '!=', 'Cancelled')
            ->orderBy('issued_at')->get();
    }

    /** What was paid on a bill, less what was handed back. */
    private function paid(Billing $b): float
    {
        return round($b->payments->filter(fn ($p) => $this->value($p->payment_status) === PaymentStatus::Paid->value)
            ->sum(fn ($p) => (float) $p->amount - (float) ($p->refunded_amount ?? 0)), 2);
    }

    private function billState(Billing $b, float $paid): string
    {
        $status = $this->value($b->status);

        return match (true) {
            $status === 'Paid' => 'Paid',
            $status === 'Refunded' => 'Refunded',
            $paid > 0 => 'Partly paid',
            $status === 'Overdue' => 'Overdue',
            default => 'Unpaid',
        };
    }

    private function sales(Collection $branches, string $from, string $to, ?string $filter): array
    {
        $rows = $this->bills($branches, $from, $to)->map(function (Billing $b) {
            $paid = $this->paid($b);
            $state = $this->billState($b, $paid);
            $closed = in_array($state, ['Paid', 'Refunded'], true);

            return ['state' => $state, 'row' => [
                $b->billing_number, $b->issued_at?->format('Y-m-d'), $this->name($b->appointment?->client) ?: 'Walk-in Client',
                $b->branch?->branch_name, $state, (float) $b->amount, $paid, $closed ? 0.0 : max(0.0, round((float) $b->amount - $paid, 2)),
            ]];
        })->filter(fn ($r) => match ($filter) {
            'paid' => $r['state'] === 'Paid',
            'refunded' => $r['state'] === 'Refunded',
            'unpaid' => ! in_array($r['state'], ['Paid', 'Refunded'], true),
            default => true,
        })->pluck('row')->sortByDesc(fn ($r) => $r[1].$r[0])->values()->all();

        return ['title' => 'Bills', 'unit' => 'bill', 'rows' => $rows, 'columns' => [
            $this->column('Bill'), $this->column('Date', 'date'), $this->column('Client'), $this->column('Branch'), $this->column('Status'),
            $this->column('Total', 'money', true), $this->column('Paid', 'money', true), $this->column('Unpaid', 'money', true),
        ]];
    }

    private function appointments(Collection $branches, string $from, string $to, ?string $filter): array
    {
        $rows = $this->visits($branches, $from, $to)
            ->filter(fn (Appointment $a) => match ($filter) {
                'walkin' => $a->appointment_type === 'Walk-in',
                'booking' => $a->appointment_type !== 'Walk-in',
                default => true,
            })
            ->sortByDesc(fn ($a) => $a->appointment_date->format('Y-m-d').substr((string) $a->appointment_time, 0, 5))
            ->map(fn (Appointment $a) => [
                $a->appointment_number, $a->appointment_date->format('Y-m-d'), $a->appointment_time ? Carbon::parse($a->appointment_time)->format('g:i A') : '',
                $this->name($a->client) ?: 'Walk-in Client', $a->branch?->branch_name,
                self::VISIT_STATUS[$this->value($a->status)] ?? $this->value($a->status), $this->total($a),
            ])->values()->all();

        return ['title' => 'Appointments', 'unit' => 'appointment', 'rows' => $rows, 'columns' => [
            $this->column('Appointment'), $this->column('Date', 'date'), $this->column('Time'), $this->column('Client'), $this->column('Branch'),
            $this->column('Status'), $this->column('Total', 'money'),
        ]];
    }

    private function clients(Collection $branches, string $from, string $to, ?string $filter): array
    {
        $done = $this->visits($branches, $from, $to)->filter(fn ($a) => $this->value($a->status) === AppointmentStatus::Completed->value);
        $rows = $done->groupBy(fn ($a) => $a->client_id ?? 'visit-'.$a->id)
            ->filter(function ($visits) use ($filter) {
                $client = $visits->first()->client;
                if ($filter === 'app') {
                    return (bool) $client?->usesApp();
                }
                if ($filter === 'member') {
                    return (bool) $client?->activeMembership()->exists();
                }

                return true;
            })
            ->map(fn ($visits) => [
                $this->name($visits->first()->client) ?: 'Walk-in Client', $visits->count(), round($visits->sum(fn ($a) => $this->total($a)), 2),
                $visits->max(fn ($a) => $a->appointment_date->format('Y-m-d')),
            ])->sortByDesc(fn ($r) => $r[2])->values()->all();

        return ['title' => 'Clients served', 'unit' => 'client', 'rows' => $rows, 'columns' => [
            $this->column('Client'), $this->column('Visits', 'number', true), $this->column('Spent', 'money', true), $this->column('Last visit', 'date'),
        ]];
    }

    private function services(Collection $branches, string $from, string $to, ?string $filter): array
    {
        $lines = $this->visits($branches, $from, $to)
            ->filter(fn ($a) => $this->value($a->status) === AppointmentStatus::Completed->value)
            ->flatMap(fn ($a) => $a->services)
            ->filter(fn ($l) => $this->value($l->status) !== AppointmentServiceStatus::Cancelled->value)
            ->map(fn ($l) => [
                'name' => $l->displayName() ?: 'Service',
                'kind' => $l->parent_appointment_service_id ? 'Add-on' : ($l->source_appointment_package_id ? 'Package' : 'Service'),
                'qty' => max(1, (int) $l->quantity), 'revenue' => (float) $l->subtotal + (float) ($l->extra_charge ?? 0),
            ]);
        $rows = $lines->groupBy('name')->map(fn ($g, $name) => [
            $name, $g->first()['kind'], $g->sum('qty'), round($g->sum('revenue'), 2), round($g->sum('revenue') / max(1, $g->sum('qty')), 2),
        ])->filter(fn ($r) => match ($filter) {
            'package' => $r[1] === 'Package',
            'service' => $r[1] === 'Service',
            default => true,
        })->sortByDesc(fn ($r) => $r[3])->values()->all();

        return ['title' => 'Services', 'unit' => 'service', 'rows' => $rows, 'columns' => [
            $this->column('Service'), $this->column('Sold as'), $this->column('Done', 'number', true), $this->column('Earned', 'money', true), $this->column('Each', 'money'),
        ]];
    }

    private function branches(Collection $branches, string $from, string $to): array
    {
        $visits = $this->visits($branches, $from, $to)->groupBy('spa_branch_id');
        $bills = $this->bills($branches, $from, $to)->groupBy('spa_branch_id');
        $rows = $branches->map(function ($branch) use ($visits, $bills) {
            $v = $visits->get($branch->id, collect());
            $finished = $v->filter(fn ($a) => $this->value($a->status) === AppointmentStatus::Completed->value);
            $cancelled = $v->filter(fn ($a) => $this->value($a->status) === AppointmentStatus::Cancelled->value)->count();
            $b = $bills->get($branch->id, collect());

            return [
                $branch->branch_name, round($b->sum(fn ($x) => $this->paid($x)), 2), $v->count() - $cancelled, $finished->count(), $cancelled,
                $finished->count() ? round($finished->sum(fn ($a) => $this->total($a)) / $finished->count(), 2) : 0.0,
            ];
        })->sortByDesc(fn ($r) => $r[1])->values()->all();

        return ['title' => 'Branches', 'unit' => 'branch', 'rows' => $rows, 'columns' => [
            $this->column('Branch'), $this->column('Paid', 'money', true), $this->column('Appointments', 'number', true), $this->column('Finished', 'number', true),
            $this->column('Cancelled', 'number', true), $this->column('Average bill', 'money'),
        ]];
    }

    private function staff(Collection $branches, string $from, string $to): array
    {
        $people = [];
        $lines = $this->visits($branches, $from, $to)
            ->filter(fn ($a) => $this->value($a->status) === AppointmentStatus::Completed->value)
            ->flatMap(fn ($a) => $a->services)
            ->filter(fn ($l) => $this->value($l->status) !== AppointmentServiceStatus::Cancelled->value);
        foreach ($lines as $line) {
            // Whoever finished it: one who handed it over part-way is not counted for it.
            $who = $line->therapistAssignments->filter(fn ($t) => $t->staff_id !== null
                && ! in_array($this->value($t->assignment_status), [AssignmentStatus::Cancelled->value, AssignmentStatus::HandedOver->value], true));
            foreach ($who as $t) {
                $row = $people[$t->staff_id] ?? ['name' => $this->name($t->staff), 'done' => 0.0, 'sales' => 0.0];
                $row['done'] += max(1, (int) $line->quantity) / $who->count();
                $row['sales'] += ((float) $line->subtotal + (float) ($line->extra_charge ?? 0)) / $who->count();
                $people[$t->staff_id] = $row;
            }
        }
        $rows = collect($people)->sortByDesc('sales')->map(fn ($r) => [$r['name'], round($r['done'], 1), round($r['sales'], 2)])->values()->all();

        return ['title' => 'Staff', 'unit' => 'person', 'rows' => $rows, 'columns' => [
            // A service two people did together counts half for each.
            $this->column('Name'), $this->column('Services', 'number', true, 1), $this->column('Sales', 'money', true),
        ]];
    }
}

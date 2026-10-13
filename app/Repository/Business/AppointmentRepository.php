<?php

namespace App\Repository\Business;

use App\Models\Appointment;
use Illuminate\Support\Str;

class AppointmentRepository
{
    private const EAGER = [
        'client',
        'branch',
        'services.serviceVariant.service',
        'services.sourceAppointmentPackage.package',
        // Which add-on an add-on line is (no query at all for a visit without one).
        'services.addon',
        'services.therapistAssignments.staff',
        'services.therapistAssignments.facility',
        'packages.package',
        'queue',
        'billing.payments',
    ];

    public function paginateForBranches(array $spaBranchIds, array $filters, int $perPage = 15)
    {
        return Appointment::with([
            'client', 'branch',
            'services.serviceVariant.service',
            'services.therapistAssignments.staff',
            'services.therapistAssignments.facility',
            // billing.payments: the board's "Collect ₱…" needs the balance
            // actually due, not the pre-discount appointment price.
            'queue', 'billing.payments',
        ])
            ->when(Appointment::remembersRequests(), fn ($q) => $q->with('requestedStaff'))
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->where('appointment_date', $date))
            // The front desk's working window (a week back, two months ahead).
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->where('appointment_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->where('appointment_date', '<=', $date))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['client_uuid'] ?? null, fn ($q, $uuid) => $q->whereHas('client', fn ($c) => $c->where('uuid', $uuid)))
            // One staff member's bookings (the employee page): the ones they
            // are assigned to, and the ones that asked for them by name even
            // if someone else was assigned.
            ->when($filters['staff_uuid'] ?? null, function ($q, $uuid) {
                $staffId = \App\Models\Staff::where('uuid', $uuid)->value('id');
                if (! $staffId) {
                    return $q->whereRaw('1 = 0');
                }
                $q->where(fn ($w) => $w
                    ->whereHas('services.therapistAssignments', fn ($t) => $t->where('staff_id', $staffId))
                    ->when(Appointment::remembersRequests(), fn ($r) => $r->orWhere('requested_staff_id', $staffId)));
            })
            ->orderByDesc('appointment_date')
            ->orderByDesc('appointment_time')
            ->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Appointment::create($payload);
    }

    public function findByUuidForBranches(string $uuid, array $spaBranchIds, array $with = self::EAGER)
    {
        return Appointment::with($with)
            ->where('uuid', $uuid)
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->firstOrFail();
    }

    // Un-scoped lookup used internally once a branch-scoped find already
    // resolved the model (e.g. after a mutation, to reload fresh relations)
    // — never call this directly from a controller-facing path.
    public function findByUuid(string $uuid, array $with = self::EAGER)
    {
        return Appointment::with($with)->where('uuid', $uuid)->firstOrFail();
    }

    public function generateAppointmentNumber(): string
    {
        do {
            $number = 'APT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (Appointment::where('appointment_number', $number)->exists());

        return $number;
    }
}

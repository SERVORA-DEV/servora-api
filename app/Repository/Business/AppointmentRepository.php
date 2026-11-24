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

    // The detail's relations, plus who does which part of a service done by several staff at once
    // (only once that table exists, Service::hasParts).
    private static function eager(): array
    {
        return \App\Models\Service::hasParts()
            ? [...self::EAGER, 'services.serviceVariant.service.parts', 'services.therapistAssignments.part']
            : self::EAGER;
    }

    /**
     * One page of the branches' visits. `$list`: only what a table shows
     * (AppointmentListResource), so none of the bill, rooms or requested staff is loaded.
     * `$sort`: 'booked' for the latest booked first, else by the visit's day and time.
     */
    public function paginateForBranches(array $spaBranchIds, array $filters, int $perPage = 15, bool $list = false, string $sort = 'date')
    {
        $with = $list
            // 'billing' (the bill row only, not its payments): a finished visit not yet paid
            // reads "Awaiting payment" in the owner's list, as it does at the desk.
            ? ['client', 'branch', 'services.serviceVariant.service', 'services.therapistAssignments.staff', 'queue', 'billing']
            : [
                'client', 'branch',
                'services.serviceVariant.service',
                'services.therapistAssignments.staff',
                'services.therapistAssignments.facility',
                // billing.payments: the board's "Collect ₱…" needs the balance
                // actually due, not the pre-discount appointment price.
                'queue', 'billing.payments',
            ];

        return AppointmentListQuery::apply(Appointment::with($with), $filters)
            ->when(! $list && Appointment::remembersRequests(), fn ($q) => $q->with('requestedStaff'))
            ->whereIn('spa_branch_id', $spaBranchIds)
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
            ->when($sort === 'booked', fn ($q) => $q->orderByDesc('created_at'))
            ->orderByDesc('appointment_date')
            ->orderByDesc('appointment_time')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Appointment::create($payload);
    }

    public function findByUuidForBranches(string $uuid, array $spaBranchIds, ?array $with = null)
    {
        $with ??= self::eager();
        return Appointment::with($with)
            ->where('uuid', $uuid)
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->firstOrFail();
    }

    // Un-scoped lookup used internally once a branch-scoped find already
    // resolved the model (e.g. after a mutation, to reload fresh relations)
    // — never call this directly from a controller-facing path.
    public function findByUuid(string $uuid, ?array $with = null)
    {
        $with ??= self::eager();
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

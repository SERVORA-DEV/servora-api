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
            'queue', 'billing',
        ])
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->where('appointment_date', $date))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['client_uuid'] ?? null, fn ($q, $uuid) => $q->whereHas('client', fn ($c) => $c->where('uuid', $uuid)))
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

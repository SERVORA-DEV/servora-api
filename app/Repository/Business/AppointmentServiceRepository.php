<?php

namespace App\Repository\Business;

use App\Models\Appointment;
use App\Models\AppointmentServiceItem;

class AppointmentServiceRepository
{
    public function create(array $payload)
    {
        return AppointmentServiceItem::create($payload);
    }

    public function findByUuidForAppointment(string $uuid, int $appointmentId)
    {
        return AppointmentServiceItem::where('uuid', $uuid)
            ->where('appointment_id', $appointmentId)
            ->firstOrFail();
    }

    // Ownership-scoped lookup for controller actions that only have the
    // service's own uuid (AppointmentServiceController) — the whereHas
    // walks up to the parent appointment's branch to enforce the same
    // scoping every other resource gets via findByUuidForBranches.
    public function findByUuidForBranches(string $uuid, array $spaBranchIds)
    {
        return AppointmentServiceItem::where('uuid', $uuid)
            ->whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $spaBranchIds))
            ->with(['serviceVariant.service', 'appointment', 'therapistAssignments.staff', 'therapistAssignments.facility'])
            ->firstOrFail();
    }

    // Status flip only — never deletes, so cancelled/completed history is
    // preserved (rule: preserve assignment/service history).
    public function cancel(AppointmentServiceItem $service): AppointmentServiceItem
    {
        $service->update(['status' => 'Cancelled']);
        return $service;
    }

    // Recomputes the appointment header's subtotal/discount/total from its
    // current non-cancelled line items — called after any add/remove so the
    // header always reflects final actual availed services, not just what
    // was selected at creation.
    public function recalculateAppointmentTotals(Appointment $appointment): void
    {
        $services = $appointment->services()->where('status', '!=', 'Cancelled')->get();
        $packages = $appointment->packages()->where('status', '!=', 'Cancelled')->get();

        $subtotal = $services->sum('subtotal') + $packages->sum('subtotal');
        $discount = $services->sum('discount_amount') + $packages->sum('discount_amount');

        $appointment->update([
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'total_amount' => $subtotal - $discount,
        ]);
    }
}

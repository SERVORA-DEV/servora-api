<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\AppointmentPackage;
use App\Models\Package;
use Illuminate\Support\Collection;

/**
 * Packages several clients share (a Couples Massage: Client A and Client B). Client A's appointment
 * buys and pays for it (client_slot 1); each other client is their own appointment, at the same
 * date and time, whose package row points back at A's (shared_from_id) and carries no price.
 *
 * Until every place is filled, Client A's booking holds the staff for the clients still to come, so
 * they always fit (openSlotsFor → ServiceParts::needOf and BookingSlotService).
 */
final class SharedPackages
{
    /**
     * For each of these appointments: how many clients its shared package is still waiting for.
     *
     * @param  iterable<int>  $appointmentIds
     * @return array<int, int> appointment id => open places (only those with some)
     */
    public static function openSlotsFor(iterable $appointmentIds): array
    {
        $ids = collect($appointmentIds)->filter()->unique()->values();
        if ($ids->isEmpty() || ! Package::hasSharing()) {
            return [];
        }

        $open = [];
        foreach (self::bought($ids->all()) as $row) {
            $left = self::clientsOf($row) - 1 - (int) $row->joined_count;
            if ($left > 0) {
                $open[$row->appointment_id] = ($open[$row->appointment_id] ?? 0) + $left;
            }
        }

        return $open;
    }

    public static function openSlots(Appointment $appointment): int
    {
        return self::openSlotsFor([$appointment->id])[$appointment->id] ?? 0;
    }

    /** The next client place still open on Client A's package (2 = Client B), or null when full. */
    public static function nextSlot(AppointmentPackage $bought): ?int
    {
        $taken = $bought->joined()->where('status', '!=', 'Cancelled')->pluck('client_slot')->map(fn ($s) => (int) $s)->all();
        foreach (range(2, max(2, self::clientsOf($bought))) as $slot) {
            if ($slot <= self::clientsOf($bought) && ! in_array($slot, $taken, true)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * What a package row on an appointment shares, for the screens: how many clients it is for, how
     * many have it, who they are, and (on Client B's) who paid. Null for an ordinary package.
     */
    public static function summary(AppointmentPackage $row): ?array
    {
        if (! Package::hasSharing()) {
            return null;
        }
        $bought = $row->shared_from_id ? $row->sharedFrom : $row;
        if (! $bought || self::clientsOf($bought) < 2) {
            return null;
        }
        $rows = collect([$bought])->merge($bought->joined()->where('status', '!=', 'Cancelled')->with('appointment.client')->get());
        $name = fn (AppointmentPackage $r) => trim(($r->appointment?->client?->first_name ?? '') . ' ' . ($r->appointment?->client?->last_name ?? '')) ?: Package::slotName((int) ($r->client_slot ?: 1));

        return [
            'client_count' => self::clientsOf($bought),
            'availed' => $rows->count(),
            'client_slot' => (int) ($row->client_slot ?: 1),
            'covered_by' => $row->shared_from_id ? $name($bought->loadMissing('appointment.client')) : null,
            'clients' => $rows->map(fn (AppointmentPackage $r) => [
                'slot' => (int) ($r->client_slot ?: 1),
                'name' => $name($r->loadMissing('appointment.client')),
                'appointment_uuid' => $r->appointment?->uuid,
            ])->sortBy('slot')->values()->all(),
        ];
    }

    /**
     * The shared packages booked at a branch on a day (and time), for the front desk to add the
     * other clients to: each with how many clients have it.
     */
    public static function onDay(int $branchId, string $date, ?string $time = null): Collection
    {
        if (! Package::hasSharing()) {
            return collect();
        }

        return AppointmentPackage::query()
            ->whereNull('shared_from_id')
            ->where('client_slot', 1)
            ->where('status', '!=', 'Cancelled')
            ->whereHas('appointment', fn ($q) => $q->where('spa_branch_id', $branchId)
                ->whereDate('appointment_date', $date)
                ->when($time, fn ($q) => $q->where('appointment_time', 'like', substr($time, 0, 5) . '%'))
                ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW]))
            ->with(['package.packageServiceItems.service', 'package.packageServiceItems.serviceVariant.service', 'appointment.client'])
            ->get()
            ->filter(fn (AppointmentPackage $row) => self::clientsOf($row) >= 2)
            ->values();
    }

    /**
     * Client A's booking was cancelled: on each other client's appointment, their place in the
     * package goes too (the package line and its services); an appointment left with nothing else
     * is cancelled. Their staff and room are let go with it.
     */
    public static function cancelJoined(Appointment $appointment): void
    {
        if (! Package::hasSharing()) {
            return;
        }
        $joined = AppointmentPackage::whereIn('shared_from_id', AppointmentPackage::where('appointment_id', $appointment->id)->whereNull('shared_from_id')->select('id'))
            ->where('status', '!=', 'Cancelled')
            ->with('appointment')
            ->get();
        foreach ($joined as $row) {
            $row->update(['status' => 'Cancelled']);
            $lines = \App\Models\AppointmentServiceItem::where('source_appointment_package_id', $row->id)->whereNotIn('status', ['Cancelled', 'Completed'])->get();
            foreach ($lines as $line) {
                $line->update(['status' => 'Cancelled']);
                \App\Models\TherapistAssignment::where('appointment_service_id', $line->id)->whereIn('assignment_status', ['Assigned'])->update(['assignment_status' => 'Cancelled']);
            }
            $theirs = $row->appointment;
            $left = $theirs?->services()->whereNotIn('status', ['Cancelled'])->exists();
            if ($theirs && ! $left && ! in_array($theirs->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_COMPLETED], true)) {
                $theirs->update(['status' => Appointment::STATUS_CANCELLED]);
            }
        }
    }

    /**
     * For each of these appointments covered by another client's shared package (Client B, C… of a
     * Couples Massage): the name of the client who bought it. One query for a whole list.
     *
     * @param  iterable<int>  $appointmentIds
     * @return array<int, string> appointment id => the main client's name (only covered ones)
     */
    public static function coveredByFor(iterable $appointmentIds): array
    {
        $ids = collect($appointmentIds)->filter()->unique()->values();
        if ($ids->isEmpty() || ! Package::hasSharing()) {
            return [];
        }

        $covered = [];
        $rows = AppointmentPackage::query()
            ->whereIn('appointment_id', $ids->all())
            ->whereNotNull('shared_from_id')
            ->where('status', '!=', 'Cancelled')
            ->with('sharedFrom.appointment.client')
            ->get();
        foreach ($rows as $row) {
            $client = $row->sharedFrom?->appointment?->client;
            $covered[$row->appointment_id] ??= trim(($client?->first_name ?? '') . ' ' . ($client?->last_name ?? '')) ?: Package::slotName(1);
        }

        return $covered;
    }

    /** Whether this appointment is covered by another client's shared package. */
    public static function isCovered(Appointment $appointment): bool
    {
        return isset(self::coveredByFor([$appointment->id])[$appointment->id]);
    }

    /**
     * Whether a visit closes with nothing to charge: another client's shared package covers it
     * and it has nothing of its own to pay (an extra service the client added is still theirs).
     * Only the client who bought the package pays for it.
     */
    public static function closesFree(float $total, bool $covered): bool
    {
        return $covered && round($total, 2) <= 0;
    }

    /** How many clients Client A's package is for. */
    public static function clientsOf(AppointmentPackage $bought): int
    {
        return max(1, (int) ($bought->package?->client_count ?? 1));
    }

    /** Client A's package rows on these appointments, with how many others joined. */
    private static function bought(array $appointmentIds): Collection
    {
        return AppointmentPackage::query()
            ->whereIn('appointment_id', $appointmentIds)
            ->whereNull('shared_from_id')
            ->where('client_slot', 1)
            ->where('status', '!=', 'Cancelled')
            ->with('package:id,client_count')
            ->withCount(['joined' => fn ($q) => $q->where('status', '!=', 'Cancelled')])
            ->get();
    }
}

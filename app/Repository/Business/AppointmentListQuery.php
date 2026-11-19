<?php

namespace App\Repository\Business;

use App\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters the appointment lists share (the owner's Appointments page, its totals and
 * charts, the front desk's lists), in one place so a list and the figures above it always
 * count the same visits.
 *
 * Filters, all optional:
 *   date, date_from, date_to   the visit's day
 *   status                      one exact status (scheduled, completed, …)
 *   list_status                 the list's dropdown: upcoming (scheduled or checked in),
 *                               in_service, completed, cancelled, no_show, or all
 *   type                        walkin, or booking (anything booked ahead)
 *   therapist                   a staff member's name as the visit shows it, or "Unassigned"
 *   service                     a service's name as the visit shows it
 *   search                      client, service or staff name, the appointment number or
 *                               the queue ticket
 *   unbilled                    only visits with no bill that are under way or done
 *
 * Names are matched on the copy the visit keeps (service_name, staff_name) first, the live
 * record second: what the list shows (App\Support\Activity\History).
 */
class AppointmentListQuery
{
    /** The list's status groups, as the page names them. */
    public const LIST_STATUSES = ['upcoming', 'in_service', 'completed', 'cancelled', 'no_show'];

    public static function statusesFor(string $listStatus): array
    {
        return match ($listStatus) {
            'upcoming' => [AppointmentStatus::Scheduled->value, AppointmentStatus::CheckedIn->value],
            'in_service' => [AppointmentStatus::InService->value],
            'completed' => [AppointmentStatus::Completed->value],
            'cancelled' => [AppointmentStatus::Cancelled->value],
            'no_show' => [AppointmentStatus::NoShow->value],
            default => [],
        };
    }

    public static function apply(Builder $query, array $filters, bool $withStatus = true): Builder
    {
        return $query
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->where('appointments.appointment_date', $date))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->where('appointments.appointment_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->where('appointments.appointment_date', '<=', $date))
            ->when($withStatus && ($filters['status'] ?? null), fn ($q) => $q->where('appointments.status', $filters['status']))
            ->when($withStatus && in_array($filters['list_status'] ?? null, self::LIST_STATUSES, true),
                fn ($q) => $q->whereIn('appointments.status', self::statusesFor($filters['list_status'])))
            ->when(($filters['type'] ?? null) === 'walkin', fn ($q) => $q->where('appointments.appointment_type', 'Walk-in'))
            ->when(($filters['type'] ?? null) === 'booking', fn ($q) => $q->where('appointments.appointment_type', '!=', 'Walk-in'))
            ->when(self::text($filters['therapist'] ?? null), fn ($q, $name) => self::byTherapist($q, $name))
            ->when(self::text($filters['service'] ?? null), fn ($q, $name) => self::byService($q, $name))
            ->when(self::text($filters['search'] ?? null), fn ($q, $text) => self::bySearch($q, $text))
            // Visits with no bill yet that could still owe one (Billing & Payments' "Not billed"
            // rows; the web decides which are finished and owing, utils/deskAttention).
            ->when(! empty($filters['unbilled']), fn ($q) => $q->whereDoesntHave('billing')
                ->whereIn('appointments.status', [AppointmentStatus::CheckedIn->value, AppointmentStatus::InService->value, AppointmentStatus::Completed->value]));
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    // "Ana Cruz" as the visit shows it: the assignment's own copy, else the staff member now.
    private static function byTherapist(Builder $q, string $name): Builder
    {
        if (strcasecmp($name, 'Unassigned') === 0) {
            return $q->whereDoesntHave('services.therapistAssignments', fn ($t) => $t->whereNotNull('staff_id')->orWhereNotNull('staff_name'));
        }

        return $q->whereHas('services.therapistAssignments', fn ($t) => $t->where(fn ($w) => $w
            ->where('staff_name', $name)
            ->orWhere(fn ($x) => $x->where(fn ($n) => $n->whereNull('staff_name')->orWhere('staff_name', ''))
                ->whereHas('staff', fn ($s) => $s->whereRaw("TRIM(TRIM(first_name) || ' ' || TRIM(last_name)) = ?", [$name])))));
    }

    private static function byService(Builder $q, string $name): Builder
    {
        return $q->whereHas('services', fn ($s) => $s->where(fn ($w) => $w
            ->where('service_name', $name)
            ->orWhere(fn ($x) => $x->where(fn ($n) => $n->whereNull('service_name')->orWhere('service_name', ''))
                ->whereHas('serviceVariant.service', fn ($v) => $v->where('name', $name)))));
    }

    // Any of the words' text in the client's name, a service, a staff member or the number.
    private static function bySearch(Builder $q, string $text): Builder
    {
        $like = '%' . mb_strtolower(addcslashes($text, '%_\\')) . '%';

        return $q->where(fn ($w) => $w
            ->whereRaw('LOWER(appointments.appointment_number) LIKE ?', [$like])
            ->orWhereHas('queue', fn ($t) => $t->whereRaw('LOWER(queue_number) LIKE ?', [$like]))
            ->orWhereHas('client', fn ($c) => $c->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like]))
            ->orWhereHas('services', fn ($s) => $s->whereRaw('LOWER(service_name) LIKE ?', [$like])
                ->orWhereHas('serviceVariant.service', fn ($v) => $v->whereRaw('LOWER(name) LIKE ?', [$like])))
            ->orWhereHas('services.therapistAssignments', fn ($t) => $t->whereRaw('LOWER(staff_name) LIKE ?', [$like])
                ->orWhereHas('staff', fn ($s) => $s->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like]))));
    }
}

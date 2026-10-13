<?php

namespace App\Service\Business;

use Illuminate\Support\Facades\DB;

// Who is performing a service right now, and which: the therapist's
// assignment is In Progress on a service that is In Progress. One small
// query, whether for one person (a check-out) or the whole roster (the
// attendance page, which greys the button out).
class ServingNow
{
    /**
     * @param  int[]  $staffIds
     * @return array<int, array{service: ?string, client: ?string}>  keyed by staff id; absent = not in a service
     */
    public function forStaff(array $staffIds): array
    {
        if (! $staffIds) {
            return [];
        }

        return DB::table('therapist_assignments as ta')
            ->join('appointment_services as s', 's.id', '=', 'ta.appointment_service_id')
            ->join('appointments as a', 'a.id', '=', 's.appointment_id')
            ->leftJoin('service_variants as v', 'v.id', '=', 's.service_variant_id')
            ->leftJoin('services as sv', 'sv.id', '=', 'v.service_id')
            ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
            ->whereIn('ta.staff_id', $staffIds)
            ->where('ta.assignment_status', 'In Progress')
            ->where('s.status', 'In Progress')
            ->whereNull('a.deleted_at')
            ->orderBy('ta.started_at')
            ->get(['ta.staff_id', DB::raw((\App\Support\History::ready() ? 'coalesce(s.service_name, sv.name)' : 'sv.name') . ' as service'), 'c.first_name', 'c.last_name'])
            ->unique('staff_id')
            ->mapWithKeys(fn ($row) => [(int) $row->staff_id => [
                'service' => $row->service,
                'client' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')) ?: null,
            ]])
            ->all();
    }

    /** @return ?array{service: ?string, client: ?string} */
    public function for(int $staffId): ?array
    {
        return $this->forStaff([$staffId])[$staffId] ?? null;
    }
}

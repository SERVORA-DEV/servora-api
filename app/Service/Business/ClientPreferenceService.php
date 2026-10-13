<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Staff;
use App\Support\FrequentTherapist;
use Illuminate\Support\Facades\DB;

// Works out each client's preferred therapist from their bookings, when
// nobody has picked one for them by hand: the therapist they have asked for
// most often (App\Support\FrequentTherapist has the rule).
//
// attach() is called on clients about to be returned (ClientService); it
// leaves the answer on each model as the `frequentTherapist` relation, which
// ClientResource reads. One query for the whole list, however many clients.
// Only therapists who still work here count — an old favourite who has left
// isn't offered at the next booking.
class ClientPreferenceService
{
    /** @param  iterable<Client>  $clients */
    public function attach(iterable $clients): void
    {
        $open = collect($clients)->filter(fn (Client $c) => ! $c->preferred_staff_id)->values();
        if ($open->isEmpty() || ! Appointment::remembersRequests()) {
            return;
        }

        $rows = DB::table('appointments as a')
            ->join('staff as s', 's.id', '=', 'a.requested_staff_id')
            ->whereIn('a.client_id', $open->pluck('id'))
            ->whereNull('a.deleted_at')
            ->whereNull('s.deleted_at')
            ->where('s.role', 'therapist')
            ->where('s.status', 'active')
            ->groupBy('a.client_id', 'a.requested_staff_id')
            ->get(['a.client_id', 'a.requested_staff_id as staff_id', DB::raw('count(*) as count'), DB::raw('max(a.created_at) as last_at')])
            ->groupBy('client_id');

        $picks = [];
        foreach ($rows as $clientId => $tallies) {
            $pick = FrequentTherapist::pick($tallies->map(fn ($t) => (array) $t)->all());
            if ($pick) {
                $picks[$clientId] = $pick;
            }
        }

        $staff = $picks ? Staff::whereIn('id', array_column($picks, 'staff_id'))->get()->keyBy('id') : collect();

        foreach ($open as $client) {
            $pick = $picks[$client->id] ?? null;
            $therapist = $pick ? $staff->get($pick['staff_id']) : null;
            // A copy per client: it carries how often THIS client asked for them.
            $client->setRelation('frequentTherapist', $therapist ? (clone $therapist)->setAttribute('requests_from_client', $pick['count']) : null);
        }
    }
}

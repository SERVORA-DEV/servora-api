<?php

namespace App\Support;

// A client's preferred therapist, worked out from their bookings: the
// therapist they have asked for by name most often
// (appointments.requested_staff_id). It only counts as a preference once
// they have asked for the same one more than once — one request is a choice
// for that day, not a habit — and a tie goes to the one asked for most
// recently. A therapist picked by hand for the client (clients.
// preferred_staff_id) always wins over this; see ClientPreferenceService.
//
// Pure, so it can be tested without a database.
final class FrequentTherapist
{
    // How many times the same therapist must have been asked for.
    public const MIN_REQUESTS = 2;

    /**
     * @param  array<int, array{staff_id: int, count: int, last_at: string}>  $tallies
     *         one row per therapist this client has asked for
     * @return array{staff_id: int, count: int}|null
     */
    public static function pick(array $tallies): ?array
    {
        $best = null;
        foreach ($tallies as $t) {
            if ((int) $t['count'] < self::MIN_REQUESTS) {
                continue;
            }
            if ($best === null
                || (int) $t['count'] > (int) $best['count']
                || ((int) $t['count'] === (int) $best['count'] && strcmp((string) $t['last_at'], (string) $best['last_at']) > 0)) {
                $best = $t;
            }
        }

        return $best ? ['staff_id' => (int) $best['staff_id'], 'count' => (int) $best['count']] : null;
    }
}

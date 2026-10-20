<?php

namespace App\Support;

// May a service start while another service of the SAME visit is still in
// progress? A client is one person: they can't be in two rooms at once, and
// one therapist can't be doing two things at once. But two therapists can
// work on the same client in the same place — a manicure during a hair
// treatment — so that is allowed.
//
// A room is never required (App\Support\ServiceKind), so a service with no
// room set says nothing about where it is — an open bed, a chair — and goes
// beside anything. Only two DIFFERENT rooms are two places.
//
//   the other service is…                          starting this one
//   in a different room                             refused
//   in the same room, different therapist(s)        allowed
//   no room on one of them, or on either            allowed (an open bed, the
//                                                   lounge chairs, a home visit)
//   with the same therapist, wherever               refused
//
// Pure: plain values in, a reason (or nothing) out — tested in
// tests/Unit/ServiceStartRulesTest. AppointmentService::startServiceInternal
// loads the visit and asks; the web mirrors it (utils/serviceStartRules.ts)
// only to grey the Start button out early. Other clients are not this
// class's business: a therapist or room busy with someone else is checked
// separately (AppointmentAvailabilityService).
class ServiceStartRules
{
    /**
     * @param  array{staff: array<int|string, string>, room: int|string|null, kind?: ?string}  $starting
     *         who is assigned to the service being started (id => name), its room, and its kind (ServiceKind)
     * @param  list<array{name: string, staff: array<int|string, string>, room: int|string|null, room_name?: ?string, kind?: ?string}>  $running
     *         the visit's services in progress right now
     * @return ?string  why it can't start yet, or null when it can
     */
    public static function problem(array $starting, array $running): ?string
    {
        // One therapist, one service at a time — said first, since no choice
        // of room gets around it. Two procedure-based services are the
        // exception: neither is measured in time, so one therapist may do
        // both together (ServiceKind::canShareTherapist).
        foreach ($running as $other) {
            $shared = array_intersect_key($starting['staff'], $other['staff']);
            if ($shared && ! ServiceKind::canShareTherapist($starting['kind'] ?? null, $other['kind'] ?? null)) {
                $names = implode(' and ', array_values($shared));

                return count($shared) === 1
                    ? "{$names} is still doing {$other['name']} for this client. Complete it first."
                    : "{$names} are still doing {$other['name']} for this client. Complete it first.";
            }
        }

        foreach ($running as $other) {
            if (self::samePlace($starting['room'] ?? null, $other['room'] ?? null)) {
                continue;
            }

            $where = ! empty($other['room_name']) ? " in {$other['room_name']}" : ' in another facility';

            return "This client is still having {$other['name']}{$where}. Complete it first, or put this service in the same facility to do both together.";
        }

        return null;
    }

    // The same room — or no room on one of them: nothing says they are apart.
    private static function samePlace(int|string|null $a, int|string|null $b): bool
    {
        return $a === null || $b === null || (string) $a === (string) $b;
    }
}

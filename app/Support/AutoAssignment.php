<?php

namespace App\Support;

// Who and what a reservation with "any staff" should be given, decided from plain arrays so it can be
// tested without a database. The facts (who is qualified and on shift, who is already booked, which
// rooms are set up and free) come from AssignmentPlanner; nothing here re-decides them.
//
// Staff: one person for the whole visit when somebody can do every service that needs staff; otherwise
// the person who fits the most remaining services takes those, then the next, and a service nobody can
// take is left for the desk with a warning. Among equals: not running late, not busy right now (for a client
// who is here), fewest bookings that day,
// then the earlier in today's turn order, then name. A gender preference comes first, and gives way
// (with a warning) only when nobody of that gender can do it.
//
// Facility: one room for everything that needs one when a single room fits all of it, else the room
// that fits the most, and so on. A service whose facility is optional only joins the shared room when it
// fits there; it is never given a room of its own.
//
// Only what is still missing is filled: an assignment a service already has stays as it is.
final class AutoAssignment
{
    /**
     * @param  array<int, array{uuid: string, name: string, needs_staff: bool, needs_facility: bool, has_staff: bool, has_facility: bool}>  $services
     * @param  array<int, array{uuid: string, name: string, gender: ?string, workload: int, position: ?int, warning?: bool, busy?: bool, can: array<string, bool>}>  $staff
     * @param  array<int, array{uuid: string, name: string, can: array<string, bool>}>  $facilities
     * @return array{staff: array<string, string>, facility: array<string, string>, warnings: array<int, string>}
     */
    public static function decide(array $services, array $staff, array $facilities, ?string $genderPreference = null): array
    {
        $preference = StaffGender::clean($genderPreference);
        $names = array_column($services, 'name', 'uuid');
        $warnings = [];

        $staffPlan = self::staffFor(
            array_values(array_filter($services, fn ($s) => $s['needs_staff'] && ! $s['has_staff'])),
            $staff,
            $preference,
            $names,
            $warnings,
        );
        $facilityPlan = self::facilitiesFor($services, $facilities, $names, $warnings);

        return ['staff' => $staffPlan, 'facility' => $facilityPlan, 'warnings' => array_values(array_unique($warnings))];
    }

    /** @return array<string, string> service uuid => staff uuid */
    private static function staffFor(array $targets, array $staff, ?string $preference, array $names, array &$warnings): array
    {
        if (! $targets) {
            return [];
        }
        $ids = array_column($targets, 'uuid');
        $ranked = self::rank($staff, $preference);

        // Anyone who can do the whole visit first (a client stays with one person), those of the preferred gender before the rest.
        foreach ($ranked as $person) {
            if (self::covers($person['can'], $ids) === $ids) {
                $plan = array_fill_keys($ids, $person['uuid']);
                self::noteGender($person, $preference, $ids, $names, $warnings);

                return $plan;
            }
        }

        // Nobody does it all: the person who fits the most takes those, then the rest are looked at again.
        $plan = [];
        $remaining = $ids;
        while ($remaining) {
            $best = null;
            $bestCovered = [];
            foreach ($ranked as $person) {
                $covered = self::covers($person['can'], $remaining);
                if (count($covered) > count($bestCovered)) {
                    $best = $person;
                    $bestCovered = $covered;
                }
            }
            if (! $best) {
                break;
            }
            foreach ($bestCovered as $id) {
                $plan[$id] = $best['uuid'];
            }
            self::noteGender($best, $preference, $bestCovered, $names, $warnings);
            $remaining = array_values(array_diff($remaining, $bestCovered));
        }

        foreach ($remaining as $id) {
            $warnings[] = 'Nobody is available for ' . ($names[$id] ?? 'a service') . '.';
        }

        return $plan;
    }

    /**
     * Staff best first: the preferred gender, fewest bookings that day, turn order (those not on the
     * floor last), name.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rank(array $staff, ?string $preference): array
    {
        usort($staff, fn ($a, $b) => [
            (int) ! StaffGender::matches($a['gender'] ?? null, $preference),
            // Someone running late or not yet in sorts after those on time.
            (int) ! empty($a['warning']),
            // For a client who is here now: someone free right now before someone mid-service.
            (int) ! empty($a['busy']),
            $a['workload'] ?? 0,
            $a['position'] ?? PHP_INT_MAX,
            strtolower((string) $a['name']),
        ] <=> [
            (int) ! StaffGender::matches($b['gender'] ?? null, $preference),
            (int) ! empty($b['warning']),
            (int) ! empty($b['busy']),
            $b['workload'] ?? 0,
            $b['position'] ?? PHP_INT_MAX,
            strtolower((string) $b['name']),
        ]);

        return $staff;
    }

    /** The ids (in order) this one can take. */
    private static function covers(array $can, array $ids): array
    {
        return array_values(array_filter($ids, fn ($id) => ! empty($can[$id])));
    }

    private static function noteGender(array $person, ?string $preference, array $ids, array $names, array &$warnings): void
    {
        if ($preference !== null && ! StaffGender::matches($person['gender'] ?? null, $preference)) {
            $what = count($ids) === 1 ? ($names[$ids[0]] ?? 'this service') : 'these services';
            $warnings[] = 'No ' . strtolower($preference) . " staff is available for {$what}, so {$person['name']} was assigned.";
        }
    }

    /** @return array<string, string> service uuid => facility uuid */
    private static function facilitiesFor(array $services, array $facilities, array $names, array &$warnings): array
    {
        $required = array_values(array_filter($services, fn ($s) => $s['needs_facility'] && ! $s['has_facility']));
        if (! $required) {
            return [];
        }
        $requiredIds = array_column($required, 'uuid');
        $optionalIds = array_column(array_filter($services, fn ($s) => ! $s['needs_facility'] && ! $s['has_facility']), 'uuid');
        $byName = $facilities;
        usort($byName, fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));

        // One room for everything that needs one; the one that also suits the optional services wins.
        $best = null;
        $bestOptional = -1;
        foreach ($byName as $room) {
            if (self::covers($room['can'], $requiredIds) !== $requiredIds) {
                continue;
            }
            $extra = count(self::covers($room['can'], $optionalIds));
            if ($extra > $bestOptional) {
                $best = $room;
                $bestOptional = $extra;
            }
        }
        if ($best) {
            $plan = array_fill_keys($requiredIds, $best['uuid']);
            foreach (self::covers($best['can'], $optionalIds) as $id) {
                $plan[$id] = $best['uuid'];
            }

            return $plan;
        }

        // No single room fits: the room that fits the most takes those, then the rest are looked at again.
        $plan = [];
        $remaining = $requiredIds;
        while ($remaining) {
            $pick = null;
            $pickCovered = [];
            foreach ($byName as $room) {
                $covered = self::covers($room['can'], $remaining);
                if (count($covered) > count($pickCovered)) {
                    $pick = $room;
                    $pickCovered = $covered;
                }
            }
            if (! $pick) {
                break;
            }
            foreach ($pickCovered as $id) {
                $plan[$id] = $pick['uuid'];
            }
            $remaining = array_values(array_diff($remaining, $pickCovered));
        }
        foreach ($remaining as $id) {
            $warnings[] = 'No facility is free for ' . ($names[$id] ?? 'a service') . '.';
        }

        return $plan;
    }
}

<?php

namespace App\Support;

// Extending a service that is in progress: the client wants longer, so the
// line is moved to a longer length of the SAME service (Swedish Massage 60 →
// 90 min) and is charged that length's price. Nothing new is added to the
// visit; the therapist and the room stay as they are.
//
// Pure: plain values in, plain values out — tested in
// tests/Unit/ServiceExtensionTest. App\Service\Business\ServiceExtensionService
// reads the visit, applies this, and saves.
class ServiceExtension
{
    /**
     * The longer lengths this service can be extended to, shortest first.
     *
     * @param  int  $currentMinutes  the length it is running at now
     * @param  float  $currentPrice  what the line is charged now
     * @param  array<int, array{uuid: string, minutes: int, price: float}>  $variants  every length of this service offered at the branch
     * @return list<array{uuid: string, minutes: int, price: float, extra_minutes: int, extra_price: float}>
     */
    public static function options(int $currentMinutes, float $currentPrice, array $variants): array
    {
        $longer = array_values(array_filter($variants, fn (array $v) => (int) $v['minutes'] > $currentMinutes));
        usort($longer, fn (array $a, array $b) => [$a['minutes'], $a['price']] <=> [$b['minutes'], $b['price']]);

        return array_map(fn (array $v) => [
            'uuid' => $v['uuid'],
            'minutes' => (int) $v['minutes'],
            'price' => round((float) $v['price'], 2),
            'extra_minutes' => (int) $v['minutes'] - $currentMinutes,
            'extra_price' => round((float) $v['price'] - $currentPrice, 2),
        ], $longer);
    }

    /**
     * Why this service can't be extended at all — null when it can.
     *
     * A service that came with a package can be: the package keeps its price
     * and only the difference between the two lengths is charged on top.
     *
     * @param  string  $serviceStatus  'Pending' | 'In Progress' | 'Completed' | 'Cancelled'
     * @param  bool  $fromPackage  it came with a package
     * @param  bool  $billed  the visit already has a bill
     * @param  ?string  $kind  its ServiceKind — a procedure-based service has one price, no lengths
     * @param  bool  $packageExtras  there is somewhere to keep what a package service's extension adds
     */
    public static function problem(string $serviceStatus, bool $fromPackage, bool $billed, ?string $kind = null, bool $packageExtras = true): ?string
    {
        if ($serviceStatus !== 'In Progress') {
            return 'Only a service that is in progress can be extended.';
        }
        if (! ServiceKind::hasLengths($kind)) {
            return 'This service has one price — there is no longer length to extend it to.';
        }
        if ($fromPackage && ! $packageExtras) {
            return 'This service came with a package, which has one price. Add the extra time as its own service instead.';
        }
        if ($billed) {
            return 'This visit already has a bill.';
        }

        return null;
    }

    /**
     * Why it can't be extended to this length — null when it can.
     *
     * @param  ?array{same_service: bool, minutes: int, offered: bool}  $target  null when the length doesn't exist
     */
    public static function targetProblem(?array $target, int $currentMinutes): ?string
    {
        if ($target === null || ! $target['same_service']) {
            return 'Choose another length of the same service.';
        }
        if (! $target['offered']) {
            return 'That length is not offered at this branch.';
        }
        if ((int) $target['minutes'] <= $currentMinutes) {
            return 'Choose a longer length than the one it is running at.';
        }

        return null;
    }

    /**
     * What is kept on the service line, so the change travels with the record.
     *
     * @param  ?float  $onTop  for a package service: what it adds on top of the package
     */
    public static function note(int $fromMinutes, int $toMinutes, string $by, ?string $existing = null, ?float $onTop = null): string
    {
        $note = "Extended {$fromMinutes} → {$toMinutes} min by {$by}"
            . ($onTop !== null ? ' (+₱' . number_format($onTop, 2) . ' on top of the package)' : '');
        $existing = trim((string) $existing);

        return $existing !== '' ? "{$existing} · {$note}" : $note;
    }

    /**
     * Who else is waiting on this therapist or this room before the new end.
     * A warning for the desk — the client in the room comes first, so it
     * never stands in the way.
     *
     * @param  int  $newEnd  minutes after midnight the service would now end
     * @param  int  $now  minutes after midnight
     * @param  int[]  $staffIds  the therapists doing the service
     * @param  int[]  $roomIds  the rooms it is in
     * @param  array<int, array{start: int, client: string, staff: int[], rooms: int[]}>  $bookings  the day's other open bookings not yet being served
     * @return list<array{kind: 'therapist'|'room', id: int, client: string, start: int, waiting: bool}>  soonest first
     */
    public static function clashes(int $newEnd, int $now, array $staffIds, array $roomIds, array $bookings): array
    {
        $found = [];
        foreach ($bookings as $booking) {
            if ((int) $booking['start'] >= $newEnd) {
                continue;
            }
            foreach ([['therapist', $staffIds, $booking['staff']], ['room', $roomIds, $booking['rooms']]] as [$kind, $mine, $theirs]) {
                foreach (array_intersect($mine, $theirs) as $id) {
                    $found[] = [
                        'kind' => $kind,
                        'id' => (int) $id,
                        'client' => $booking['client'],
                        'start' => (int) $booking['start'],
                        'waiting' => (int) $booking['start'] <= $now,
                    ];
                }
            }
        }
        usort($found, fn (array $a, array $b) => [$a['start'], $a['kind']] <=> [$b['start'], $b['kind']]);

        return $found;
    }
}

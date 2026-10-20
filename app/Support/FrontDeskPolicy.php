<?php

namespace App\Support;

use Carbon\CarbonInterface;

// The owner's booking rules (Settings → Booking & Policies), and a manager's
// own rules for their branch, as they apply to what the FRONT DESK does.
// The client app has been held to them for a while (BookingPolicy); the desk
// was not, and several settings were saved but read by nothing.
//
// Each check takes the branch's resolved policy (BookingPolicy::for) and plain
// values, and gives back the rules that are broken:
//     ['rule' => key, 'message' => what to tell the desk, 'overridable' => bool]
// A rule that is a switch (reservations off, extension off) can't be
// overridden — it is changed in Settings. A limit can, by someone allowed to,
// with a reason that is kept (App\Support\PolicyOverride).
//
// Pure — tested in tests/Unit/FrontDeskPolicyTest.
// App\Service\Business\PolicyGate applies these and records overrides.
class FrontDeskPolicy
{
    /**
     * A reservation made at the desk.
     *
     * @param  int  $services  how many services (a package counts as one)
     * @param  int  $minutes  their total length
     * @param  ?array{name: string, no_shows: int}  $client  the client it is for, when known
     */
    public static function reservation(array $policy, CarbonInterface $startsAt, CarbonInterface $now, int $services = 0, int $minutes = 0, ?array $client = null): array
    {
        $problems = self::window($policy, $startsAt, $now);

        $max = (int) ($policy['max_services_per_booking'] ?? 0);
        if ($max > 0 && $services > $max) {
            $problems[] = self::rule('max_services', "A booking can have up to {$max} " . ($max === 1 ? 'service' : 'services') . " here — this one has {$services}.");
        }

        $limit = (int) ($policy['max_service_minutes_per_booking'] ?? 0);
        if ($limit > 0 && $minutes > $limit) {
            $problems[] = self::rule('service_time', 'A booking can hold up to ' . self::length($limit) . ' of services here — these take ' . self::length($minutes) . '.');
        }

        $after = (int) ($policy['no_show_suspend_after'] ?? 0);
        if ($client && ($policy['no_show_policy'] ?? 'warn') === 'suspend' && $after > 0 && $client['no_shows'] >= $after) {
            $problems[] = self::rule('client_suspended', "{$client['name']} has missed {$client['no_shows']} bookings — booking is paused after {$after}.");
        }

        return $problems;
    }

    /** The notice and how-far-ahead rules, for a start time (a new booking, or one being moved). */
    public static function window(array $policy, CarbonInterface $startsAt, CarbonInterface $now): array
    {
        $problems = [];

        $lead = (int) ($policy['booking_lead_time_minutes'] ?? 0);
        $away = (int) floor($now->diffInMinutes($startsAt, false));
        if ($lead > 0 && $away < $lead) {
            $problems[] = self::rule('lead_time', 'Bookings need ' . self::length($lead) . ' of notice — this one ' . ($away > 0 ? 'starts in ' . self::length($away) : 'is for right now or earlier') . '.');
        }

        $days = (int) ($policy['max_advance_booking_days'] ?? 0);
        $ahead = (int) $now->copy()->startOfDay()->diffInDays($startsAt->copy()->startOfDay(), false);
        if ($days > 0 && $ahead > $days) {
            $problems[] = self::rule('max_advance', "Bookings can be made up to {$days} days ahead — this one is {$ahead} days away.");
        }

        return $problems;
    }

    /**
     * Cancelling or moving a booking: inside the cancellation window it is a
     * late change. (Once the start time has passed there is nothing left to
     * give notice of.)
     *
     * @param  string  $action  'cancel' | 'reschedule'
     */
    public static function change(array $policy, CarbonInterface $startsAt, CarbonInterface $now, string $action): array
    {
        $window = (int) ($policy['cancellation_window_hours'] ?? 0);
        if ($window <= 0 || $startsAt->lte($now) || $startsAt->gte($now->copy()->addHours($window))) {
            return [];
        }

        $verb = $action === 'cancel' ? 'cancelled' : 'moved';
        $away = (int) floor($now->diffInMinutes($startsAt, false));

        return [self::rule('late_change', "Bookings can be {$verb} up to {$window} " . ($window === 1 ? 'hour' : 'hours') . ' before they start — this one starts in ' . self::length(max(1, $away)) . '.')];
    }

    /** Putting one more client in the line (a walk-in, or a check-in). */
    public static function queue(array $policy, int $waiting): array
    {
        $max = (int) ($policy['max_queue_size'] ?? 0);
        if (! ($policy['queue_enabled'] ?? true) || $max <= 0 || $waiting < $max) {
            return [];
        }

        return [self::rule('queue_full', "The queue is full — {$waiting} waiting, and this branch takes up to {$max}.")];
    }

    /**
     * Extending a service in progress.
     *
     * @param  int  $alreadyExtended  minutes this service has been extended by so far
     * @param  int  $extraMinutes  what this extension adds
     */
    public static function extension(array $policy, int $alreadyExtended, int $extraMinutes): array
    {
        if (! ($policy['allow_service_extension'] ?? true)) {
            return [self::rule('extension_off', 'Extending a service is switched off (Settings → Booking & Policies).', false)];
        }

        $problems = [];
        $max = (int) ($policy['max_extension_mins'] ?? 0);
        $total = $alreadyExtended + $extraMinutes;
        if ($max > 0 && $total > $max) {
            $problems[] = self::rule('max_extension', 'A service can be extended by up to ' . self::length($max) . ' — this would make it ' . self::length($total) . '.');
        }
        if ($policy['extension_requires_approval'] ?? false) {
            $problems[] = self::rule('extension_approval', 'Extending a service needs approval.');
        }

        return $problems;
    }

    /**
     * What a no-show leads to, under the no-show policy.
     *
     * @param  int  $noShows  the client's no-shows, this one included
     * @return 'none'|'warn'|'suspend'
     */
    public static function noShowAction(array $policy, int $noShows): string
    {
        $rule = $policy['no_show_policy'] ?? 'warn';
        if ($rule === 'none') {
            return 'none';
        }
        $after = (int) ($policy['no_show_suspend_after'] ?? 0);

        return $rule === 'suspend' && $after > 0 && $noShows >= $after ? 'suspend' : 'warn';
    }

    /** Is this client's booking from the app paused for missed bookings? */
    public static function clientSuspended(array $policy, int $noShows): bool
    {
        $after = (int) ($policy['no_show_suspend_after'] ?? 0);

        return ($policy['no_show_policy'] ?? 'warn') === 'suspend' && $after > 0 && $noShows >= $after;
    }

    private static function rule(string $rule, string $message, bool $overridable = true): array
    {
        return ['rule' => $rule, 'message' => $message, 'overridable' => $overridable];
    }

    // 30 → "30 minutes", 90 → "1 hour 30 minutes", 2880 → "2 days".
    private static function length(int $minutes): string
    {
        if ($minutes >= 2880 && $minutes % 1440 === 0) {
            return ($minutes / 1440) . ' days';
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . ($hours === 1 ? ' hour' : ' hours');
        }
        if ($rest > 0 || ! $parts) {
            $parts[] = $rest . ($rest === 1 ? ' minute' : ' minutes');
        }

        return implode(' ', $parts);
    }
}

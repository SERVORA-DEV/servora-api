<?php

namespace App\Service\Client;

use App\Models\SpaBranch;
use App\Models\SpaBusinessSetting;
use Carbon\Carbon;

// The booking rules a client's self-booking must follow at one branch: the
// owner's company-wide Settings → Booking defaults, with that branch's own
// overrides (Branch Settings → Booking policy) layered on top. Until now
// these were stored but never read — this is the one place that resolves
// them, so booking, reschedule, cancel and the app all agree.
class BookingPolicy
{
    // Always on, so not rules at all: a listed branch takes bookings from the
    // app, a client may pick their staff (when the branch shows its staff), the
    // front desk takes reservations, and a service with no one picked gets a
    // qualified therapist assigned. Any value saved for them is ignored.
    public const KEYS = [
        'max_advance_booking_days',
        'max_services_per_booking',
        // How many bookings one client may have at the business on one day
        // (the client app is held to it; the front desk isn't).
        'max_bookings_per_client_per_day',
        'max_service_minutes_per_booking',
        'allow_reschedule',
        'max_reschedules_per_booking',
        'allow_cancellation',
        'cancellation_window_hours',
        // What the front desk is held to as well (App\Support\FrontDeskPolicy).
        'no_show_policy',
        'no_show_suspend_after',
        'queue_enabled',
        'max_queue_size',
        'queue_number_format',
        'walk_ins_first_priority',
        'allow_service_extension',
        'max_extension_mins',
        'extension_requires_approval',
        // Home service — see App\Service\Business\HomeServiceRules.
        'home_service_enabled',
        'home_service_fee',
        'home_service_radius_km',
        'home_service_min_minutes',
    ];

    // How far ahead a booking must be made. The same for every spa and branch,
    // so it isn't a setting; the policy still carries it for the app and desk.
    public const LEAD_TIME_MINUTES = 60;

    // Per-request cache (a bookings or spa list asks once per row). Scoped
    // in the container, so a queue worker or long-lived process starts each
    // job/request fresh instead of keeping a stale copy.
    private const CACHE = 'booking-policy.cache';

    public static function for(SpaBranch $branch): array
    {
        $cache = self::cache();

        return $cache[$branch->id] ??= self::resolve($branch);
    }

    // Whether a client may pick their therapist at this branch: when the branch
    // shows its staff (Branch Settings → Marketplace → Show staff profiles).
    // Otherwise the app has no therapist step.
    public static function clientMayChooseTherapist(SpaBranch $branch): bool
    {
        return $branch->displaySettings()['show_therapist_profiles'];
    }

    // Drops cached policies — after the rules change within one request/test.
    public static function flush(): void
    {
        app()->forgetInstance(self::CACHE);
    }

    private static function cache(): \ArrayObject
    {
        if (! app()->bound(self::CACHE)) {
            app()->scoped(self::CACHE, fn () => new \ArrayObject());
        }

        return app(self::CACHE);
    }

    private static function resolve(SpaBranch $branch): array
    {
        // The business's settings come from AppCache unless already loaded —
        // a spa list would otherwise query them once per business.
        $settings = $branch->relationLoaded('business') && $branch->business?->relationLoaded('settings')
            ? $branch->business->settings
            : \App\Support\AppCache::businessSettings($branch->spa_business_id);
        $defaults = $settings?->section('booking_defaults')
            ?? SpaBusinessSetting::DEFAULTS['booking_defaults'];
        $overrides = (array) ($branch->booking_overrides ?? []);

        $policy = [];
        foreach (self::KEYS as $key) {
            $value = array_key_exists($key, $overrides) ? $overrides[$key] : ($defaults[$key] ?? null);
            $default = SpaBusinessSetting::DEFAULTS['booking_defaults'][$key];
            $policy[$key] = match (true) {
                is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                is_string($default) => is_string($value) && $value !== '' ? $value : $default,
                default => max(0, (int) $value),
            };
        }

        $policy['max_bookings_per_client_per_day'] = max(1, $policy['max_bookings_per_client_per_day']);
        $policy['booking_lead_time_minutes'] = self::LEAD_TIME_MINUTES;

        return $policy;
    }

    // Manila "now" — the app's timezone (config/app.php), spelled out so
    // policy math never depends on the server clock's zone.
    public static function now(): Carbon
    {
        return Carbon::now('Asia/Manila');
    }

    // What the client is told about their missed bookings under the branch's
    // no-show policy ("Pause their booking in the app after N"): how many they
    // have missed since they last came in, whether booking is paused, and the
    // words for it. Nothing to say unless the policy pauses booking.
    //
    // @return array{missed: int, limit: ?int, blocked: bool, message: ?string}
    public static function missedBookingsNotice(array $policy, int $missed, string $spa = ''): array
    {
        $limit = (int) ($policy['no_show_suspend_after'] ?? 0);
        if (($policy['no_show_policy'] ?? 'warn') !== 'suspend' || $limit <= 0) {
            return ['missed' => $missed, 'limit' => null, 'blocked' => false, 'message' => null];
        }

        $who = $spa !== '' ? $spa : 'the spa';
        $blocked = $missed >= $limit;
        $times = $missed === 1 ? '1 booking' : "{$missed} bookings";

        return [
            'missed' => $missed,
            'limit' => $limit,
            'blocked' => $blocked,
            'message' => match (true) {
                $blocked => "Booking in the app is paused: you missed {$times} without a visit in between. Please contact {$who} to book.",
                $missed > 0 => "You missed {$times}. Missing {$limit} without a visit in between pauses booking in the app; coming to your next visit starts the count again.",
                default => null,
            },
        ];
    }

    // Why a client may not book this start time under $policy, or null.
    public static function startTimeProblem(array $policy, Carbon $startsAt): ?string
    {
        $now = self::now();

        if ($startsAt->lt($now)) {
            return 'Please choose a future time.';
        }

        $lead = $policy['booking_lead_time_minutes'];
        if ($lead > 0 && $startsAt->lt($now->copy()->addMinutes($lead))) {
            return 'Please book at least ' . self::formatMinutes($lead) . ' ahead.';
        }

        $days = $policy['max_advance_booking_days'];
        if ($days > 0 && $startsAt->copy()->startOfDay()->gt($now->copy()->startOfDay()->addDays($days))) {
            return "You can book up to {$days} days ahead.";
        }

        return null;
    }

    // Why a booking of $minutes is over this branch's "service time per
    // booking" limit, or null when it fits (or there is no limit). The client
    // app is refused with this; the front desk only sees it as a warning.
    public static function serviceTimeProblem(array $policy, int $minutes): ?string
    {
        $limit = (int) ($policy['max_service_minutes_per_booking'] ?? 0);

        if ($limit <= 0 || $minutes <= $limit) {
            return null;
        }

        return 'This spa books up to ' . self::formatMinutes($limit) . ' per visit, and these services take '
            . self::formatMinutes($minutes) . '. Remove a service, or book a second visit.';
    }

    // 120 → "2 hours", 90 → "1 hour 30 minutes", 45 → "45 minutes".
    public static function formatMinutes(int $minutes): string
    {
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

    // Why a client may not cancel/reschedule now (null = allowed). Only the
    // policy side — ClientAppointmentResource::clientCanChange still owns
    // "is this booking still just a reservation".
    // $reschedules: how many times the client has already moved this booking.
    public static function changeProblem(array $policy, Carbon $startsAt, string $action, int $reschedules = 0): ?string
    {
        $allowed = $action === 'cancel' ? $policy['allow_cancellation'] : $policy['allow_reschedule'];
        if (! $allowed) {
            return $action === 'cancel'
                ? "This spa doesn't allow cancelling from the app. Please contact the spa."
                : "This spa doesn't allow rescheduling from the app. Please contact the spa.";
        }

        if ($action !== 'cancel') {
            $limit = (int) ($policy['max_reschedules_per_booking'] ?? 0);
            if ($limit <= 0) {
                return "This spa doesn't allow rescheduling from the app. Please contact the spa.";
            }
            if ($reschedules >= $limit) {
                return "You've already moved this booking {$reschedules} " . ($reschedules === 1 ? 'time' : 'times')
                    . ", the most this spa allows. Please contact the spa.";
            }
        }

        $window = $policy['cancellation_window_hours'];
        if ($window > 0 && $startsAt->lt(self::now()->addHours($window))) {
            $verb = $action === 'cancel' ? 'cancelled' : 'rescheduled';

            return "Bookings can only be {$verb} up to {$window} hours before the start time. Please contact the spa.";
        }

        return null;
    }
}

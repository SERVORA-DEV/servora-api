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
    public const KEYS = [
        'online_booking_enabled',
        'booking_lead_time_minutes',
        'max_advance_booking_days',
        'max_services_per_booking',
        'allow_reschedule',
        'allow_cancellation',
        'cancellation_window_hours',
        'walk_in_enabled',
        'cancellation_policy_tier',
    ];

    public const TIERS = ['flexible', 'moderate', 'strict'];

    // Per-request cache (a bookings or spa list asks once per row). Scoped
    // in the container, so a queue worker or long-lived process starts each
    // job/request fresh instead of keeping a stale copy.
    private const CACHE = 'booking-policy.cache';

    public static function for(SpaBranch $branch): array
    {
        $cache = self::cache();

        return $cache[$branch->id] ??= self::resolve($branch);
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

        // The refund share of the tier in force here. The tiers' percentages
        // are company-wide (Business Defaults → Refund by cancellation
        // policy); only which tier applies can differ per branch.
        if (! in_array($policy['cancellation_policy_tier'], self::TIERS, true)) {
            $policy['cancellation_policy_tier'] = SpaBusinessSetting::DEFAULTS['booking_defaults']['cancellation_policy_tier'];
        }
        $tiers = $defaults['cancellation_tiers'] ?? SpaBusinessSetting::DEFAULTS['booking_defaults']['cancellation_tiers'];
        $policy['refund_percent'] = min(100, max(0, (int) ($tiers[$policy['cancellation_policy_tier']] ?? 0)));

        return $policy;
    }

    // Manila "now" — the app's timezone (config/app.php), spelled out so
    // policy math never depends on the server clock's zone.
    public static function now(): Carbon
    {
        return Carbon::now('Asia/Manila');
    }

    // Why a client may not book this start time under $policy, or null.
    public static function startTimeProblem(array $policy, Carbon $startsAt): ?string
    {
        $now = self::now();

        if (! $policy['online_booking_enabled']) {
            return "This spa isn't taking online bookings right now. Please call or walk in.";
        }

        if ($startsAt->lt($now)) {
            return 'Please choose a future time.';
        }

        $lead = $policy['booking_lead_time_minutes'];
        if ($lead > 0 && $startsAt->lt($now->copy()->addMinutes($lead))) {
            return "Please book at least {$lead} minutes ahead.";
        }

        $days = $policy['max_advance_booking_days'];
        if ($days > 0 && $startsAt->copy()->startOfDay()->gt($now->copy()->startOfDay()->addDays($days))) {
            return "You can book up to {$days} days ahead.";
        }

        return null;
    }

    // Why a client may not cancel/reschedule now (null = allowed). Only the
    // policy side — ClientAppointmentResource::clientCanChange still owns
    // "is this booking still just a reservation".
    public static function changeProblem(array $policy, Carbon $startsAt, string $action): ?string
    {
        $allowed = $action === 'cancel' ? $policy['allow_cancellation'] : $policy['allow_reschedule'];
        if (! $allowed) {
            return $action === 'cancel'
                ? "This spa doesn't allow cancelling from the app. Please contact the spa."
                : "This spa doesn't allow rescheduling from the app. Please contact the spa.";
        }

        $window = $policy['cancellation_window_hours'];
        if ($window > 0 && $startsAt->lt(self::now()->addHours($window))) {
            $verb = $action === 'cancel' ? 'cancelled' : 'rescheduled';

            return "Bookings can only be {$verb} up to {$window} hours before the start time. Please contact the spa.";
        }

        return null;
    }
}

<?php

namespace App\Support;

use App\Models\SpaBusinessSetting;
use App\Models\Subscription;
use App\Models\SystemSetting;
use App\Models\UserPermission;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Read-mostly data that would otherwise cost a remote database round trip on
// almost every request. One place owns every key, its lifetime and how it is
// invalidated:
//
//   - each value is kept for the rest of the request (so repeated lookups in
//     middleware + service are free) and in the cache store (CACHE_STORE,
//     local disk in production) across requests;
//   - writes invalidate through model events (CacheInvalidation, registered in
//     AppServiceProvider) and a few explicit calls where Laravel fires none
//     (pivot syncs, query-builder updates);
//   - the TTLs are only a safety net for anything those miss.
//
// Values handed out here are for READING. Code that changes a row loads it
// from the database itself, so it never saves over a cached copy.
final class AppCache
{
    private const SETTINGS_TTL = 3600;
    private const SUBSCRIPTION_TTL = 600;
    private const PERMISSION_TTL = 600;
    private const IDENTITY_TTL = 600;

    // ── Core ──────────────────────────────────────────────────────────────

    /** Request memo first, then the store. Caches nulls too. */
    public static function remember(string $key, int $ttl, Closure $resolve): mixed
    {
        $memo = self::memo();
        if ($memo->offsetExists($key)) {
            return $memo[$key];
        }

        $hit = Cache::get($key);
        if (is_array($hit) && array_key_exists('v', $hit)) {
            return $memo[$key] = $hit['v'];
        }

        $value = $resolve();
        self::store($key, $value, $ttl);

        return $memo[$key] = $value;
    }

    // Inside a transaction the value may include uncommitted (or later
    // rolled back) changes, so it is kept for this request only.
    private static function store(string $key, mixed $value, int $ttl): void
    {
        if (DB::transactionLevel() === 0) {
            Cache::put($key, ['v' => $value], $ttl);
        }
    }

    public static function forget(string ...$keys): void
    {
        $memo = self::memo();
        foreach ($keys as $key) {
            Cache::forget($key);
            $memo->offsetUnset($key);
        }
    }

    /** Request-scoped, so nothing leaks into the next request. */
    private static function memo(): \ArrayObject
    {
        if (! app()->bound('servora.app_cache')) {
            app()->scoped('servora.app_cache', fn () => new \ArrayObject());
        }

        return app('servora.app_cache');
    }

    // ── Platform ──────────────────────────────────────────────────────────

    public static function systemSettings(): SystemSetting
    {
        return self::remember('sys:settings', self::SETTINGS_TTL, fn () => SystemSetting::current());
    }

    public static function graceDays(): int
    {
        return (int) self::systemSettings()->subscription_grace_period_days;
    }

    // ── Per business ──────────────────────────────────────────────────────

    /**
     * The business's active (or in-grace) subscription with its plan, or null.
     * Cached no longer than the subscription can stay valid.
     */
    public static function activeSubscription(int $businessId): ?Subscription
    {
        $key = self::subscriptionKey($businessId);
        $memo = self::memo();
        if ($memo->offsetExists($key)) {
            return $memo[$key];
        }

        $hit = Cache::get($key);
        if (is_array($hit) && array_key_exists('v', $hit)) {
            return $memo[$key] = $hit['v'];
        }

        $grace = self::graceDays();
        $subscription = app(\App\Repository\SubscriptionRepository::class)
            ->findActiveOrInGraceForBusiness($businessId, $grace);
        $subscription?->loadMissing('plan');

        $ttl = self::SUBSCRIPTION_TTL;
        if ($subscription?->expires_at) {
            $lapses = $subscription->expires_at->copy()->addDays($grace);
            $ttl = max(1, min($ttl, (int) now()->diffInSeconds($lapses, false)));
        }
        self::store($key, $subscription, $ttl);

        return $memo[$key] = $subscription;
    }

    public static function planAllows(int $businessId, string $feature): bool
    {
        return (bool) self::activeSubscription($businessId)?->plan?->{$feature};
    }

    public static function forgetSubscription(int $businessId): void
    {
        self::forget(self::subscriptionKey($businessId));
    }

    // Changing the grace period or any plan changes every business's answer,
    // so both are part of the key rather than forgotten one by one.
    private static function subscriptionKey(int $businessId): string
    {
        return "biz:{$businessId}:sub:g" . self::graceDays() . ':p' . self::version('plans');
    }

    public static function businessSettings(int $businessId): ?SpaBusinessSetting
    {
        return self::remember("biz:{$businessId}:settings", self::SETTINGS_TTL,
            fn () => SpaBusinessSetting::where('spa_business_id', $businessId)->first());
    }

    // ── Per user ──────────────────────────────────────────────────────────

    public static function permission(int $userId): ?UserPermission
    {
        return self::remember("user:{$userId}:perm", self::PERMISSION_TTL,
            fn () => UserPermission::where('user_id', $userId)->first());
    }

    /** The owner's identity verification status ('Unregistered' if none). */
    public static function identityStatus(int $userId): string
    {
        return self::remember("user:{$userId}:idv", self::IDENTITY_TTL,
            fn () => DB::table('owner_identity_verifications')->where('user_id', $userId)->value('status') ?? 'Unregistered');
    }

    // ── Versions (for whole-response caches) ──────────────────────────────

    /** A counter that changes whenever the named thing changes. */
    public static function version(string $name): int
    {
        return (int) self::remember("ver:{$name}", 86400 * 30, fn () => 1);
    }

    public static function bump(string $name): void
    {
        $key = "ver:{$name}";
        $next = max((int) (Cache::get($key)['v'] ?? 0), self::version($name)) + 1;
        Cache::put($key, ['v' => $next], 86400 * 30);
        self::memo()[$key] = $next;
    }

    /** Anything a client sees about this business changed. */
    public static function bumpBusiness(?int $businessId): void
    {
        if (! $businessId) {
            return;
        }
        self::bump("biz:{$businessId}");
        self::bump('marketplace');
    }

    public static function businessVersion(int $businessId): int
    {
        return self::version("biz:{$businessId}");
    }
}

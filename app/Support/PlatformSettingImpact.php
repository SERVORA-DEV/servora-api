<?php

namespace App\Support;

use App\Models\SpaBranch;
use App\Models\Subscription;
use App\Service\Business\MarketplaceReadiness;
use Illuminate\Support\Collection;

// What a change to the platform's settings would do to businesses that are
// operating, worked out before it is saved so the administrator sees it, and
// afterwards so each owner is told:
//  - a listing item made Required hides every listed branch that does not have it
//    from clients at once (and stops them booking or moving a booking there);
//  - a shorter grace period cuts off, at once, the owners whose subscription ended
//    inside the old one.
class PlatformSettingImpact
{
    /**
     * The listing items a new policy makes Required that are not Required now.
     *
     * @return list<string>
     */
    public static function newlyRequired(?array $newPolicy): array
    {
        if ($newPolicy === null) {
            return [];
        }
        $current = ListingPolicy::current();

        return array_keys(array_filter(
            ListingPolicy::normalize($newPolicy),
            fn ($value, $key) => $value === ListingPolicy::REQUIRED && ($current[$key] ?? ListingPolicy::OPTIONAL) !== ListingPolicy::REQUIRED,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /** Whether a branch has one listing item (the same test MarketplaceReadiness::polish makes). */
    public static function has(SpaBranch $branch, string $key): bool
    {
        $branch->loadMissing('business', 'photos');

        return match ($key) {
            'cover_photo' => filled($branch->cover_photo) || $branch->photos->isNotEmpty(),
            'description' => trim((string) $branch->business?->business_description) !== '',
            'highlights' => count($branch->highlights ?? []) >= ListingPolicy::MIN_HIGHLIGHTS,
            'promo' => trim((string) $branch->promo_text) !== '',
            default => true,
        };
    }

    /**
     * Branches clients can book now that the new policy would stop being listed.
     *
     * @return Collection<int, SpaBranch>
     */
    public static function branchesHiddenBy(?array $newPolicy): Collection
    {
        $added = self::newlyRequired($newPolicy);
        if (! $added) {
            return collect();
        }

        return MarketplaceReadiness::scope(SpaBranch::query()
            ->where('verification_status', 'Verified')
            ->where('operating_status', 'Active')
            ->where('listing_visible', true))
            ->with(['business', 'photos'])
            ->get()
            ->filter(fn (SpaBranch $b) => collect($added)->contains(fn ($key) => ! self::has($b, $key)))
            ->values();
    }

    /** What a branch is missing of the newly Required items, in the owner's words. */
    public static function missingLabels(SpaBranch $branch, array $added): array
    {
        return collect($added)->filter(fn ($key) => ! self::has($branch, $key))->map(fn ($key) => ListingPolicy::ITEMS[$key] ?? $key)->values()->all();
    }

    /**
     * Subscriptions that stop counting at once under a shorter grace period.
     *
     * @return Collection<int, Subscription>
     */
    public static function subscriptionsCutBy(?int $newGraceDays): Collection
    {
        $old = AppCache::graceDays();
        if ($newGraceDays === null || $newGraceDays >= $old) {
            return collect();
        }

        return Subscription::with('business.owner')
            ->where('status', 'Active')
            ->where('is_trial', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->subDays($newGraceDays))
            ->where('expires_at', '>', now()->subDays($old))
            ->where(fn ($q) => $q->whereNull('plan_change_status')->orWhere('plan_change_status', '!=', 'declined'))
            ->whereRaw('subscriptions.id = (select max(s2.id) from subscriptions s2 where s2.spa_business_id = subscriptions.spa_business_id and s2.deleted_at is null)')
            ->get();
    }
}

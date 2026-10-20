<?php

namespace App\Service\Business;

use App\Models\SpaBranch;
use App\Repository\Business\SpaBranchRepository;
use App\Service\Client\BookingPolicy;
use App\Support\ListingPolicy;
use Illuminate\Database\Eloquent\Builder;

// Whether a branch can actually take a booking from the client app, so the
// marketplace only lists branches a client won't dead-end at. Ready means:
//  - the business is verified (not pending or suspended);
//  - at least one service or package is on offer;
//  - opening hours are set for at least one day;
//  - at least one active therapist who works at least one day;
//  - online booking is switched on (BookingPolicy);
//  - every listing item the platform's administrator marked Required is
//    done — cover photo, description, highlights, promo banner (ListingPolicy).
// On top of the public guard every listing already applies (branch Verified,
// Active, listed and pinned — see SpaBranchRepository).
//
// scope() is the SQL form used by browse lists; check() is the same rule,
// item by item, for the owner's checklist and the spa page. A listed branch
// that passes it takes bookings from the app.
class MarketplaceReadiness
{
    public const NOT_BOOKABLE = "This spa isn't taking bookings right now.";

    public static function scope(Builder $query): Builder
    {
        $query
            ->whereHas('business', fn ($q) => $q->where('verification_status', 'Verified'))
            ->where(fn ($q) => $q
                ->whereHas('branchServices', fn ($s) => SpaBranchRepository::publicServices($s))
                ->orWhereHas('branchPackages', fn ($p) => SpaBranchRepository::publicPackages($p)))
            ->whereHas('schedules', fn ($q) => self::openDays($q))
            ->whereHas('staff', fn ($q) => self::workingTherapists($q));

        foreach (ListingPolicy::requiredKeys() as $key) {
            self::polishScope($query, $key);
        }

        return $query;
    }

    // The SQL form of one Required listing item — the same test polish()
    // makes in PHP for the owner's checklist.
    private static function polishScope(Builder $query, string $key): void
    {
        match ($key) {
            // A gallery photo, or the photo from the branch's registration.
            'cover_photo' => $query->where(fn ($q) => $q
                ->whereHas('photos')
                ->orWhereRaw("trim(coalesce(spa_branches.cover_photo, '')) <> ''")),
            'description' => $query->whereHas('business', fn ($q) => $q
                ->whereRaw("trim(coalesce(business_description, '')) <> ''")),
            'highlights' => $query->whereJsonLength('spa_branches.highlights', '>=', ListingPolicy::MIN_HIGHLIGHTS),
            'promo' => $query->whereRaw("trim(coalesce(spa_branches.promo_text, '')) <> ''"),
            default => null,
        };
    }

    /**
     * The listing items for one branch. First the two no branch can be booked
     * without — something on offer, and staff to do it — which are always
     * required (`fixed`); then the four the administrator's policy decides.
     *
     * check() passes in what it already worked out for the first two.
     *
     * @return list<array{key: string, label: string, done: bool, required: bool, fixed: bool}>
     */
    public static function polish(SpaBranch $branch, ?bool $hasOffer = null, ?bool $hasStaff = null): array
    {
        $branch->loadMissing('business');
        $policy = ListingPolicy::current();

        $items = [
            ['key' => 'services', 'label' => 'Service or package on offer', 'required' => true, 'fixed' => true,
                'done' => $hasOffer ?? self::hasOffer($branch)],
            ['key' => 'staff', 'label' => 'Staff ready to accept bookings', 'required' => true, 'fixed' => true,
                'done' => $hasStaff ?? self::workingTherapists($branch->staff())->exists()],
        ];

        $done = [
            'cover_photo' => filled($branch->cover_photo) || $branch->photos()->exists(),
            'description' => trim((string) $branch->business?->business_description) !== '',
            'highlights' => count($branch->highlights ?? []) >= ListingPolicy::MIN_HIGHLIGHTS,
            'promo' => trim((string) $branch->promo_text) !== '',
        ];

        foreach (ListingPolicy::ITEMS as $key => $label) {
            $items[] = [
                'key' => $key,
                'label' => $label,
                'done' => $done[$key],
                'required' => $policy[$key] === ListingPolicy::REQUIRED,
                'fixed' => false,
            ];
        }

        return $items;
    }

    private static function hasOffer(SpaBranch $branch): bool
    {
        return SpaBranchRepository::publicServices($branch->branchServices())->exists()
            || SpaBranchRepository::publicPackages($branch->branchPackages())->exists();
    }

    // The whole rule for one branch — what booking and the time slots check.
    // (The spa page itself stays reachable through publicFindByUuid(), so a
    // client with an existing booking can still open it.)
    public static function isBookable(SpaBranch $branch): bool
    {
        return self::scope(SpaBranch::whereKey($branch->id))->exists();
    }

    // `items` is everything that has to be done to be listed (the Required
    // listing items included); `polish` is all four listing items with their
    // Required/Optional flag, for the owner's Listing polish card.
    //
    // @return array{ready: bool, items: list<array{key: string, label: string, done: bool}>, polish: list<array{key: string, label: string, done: bool, required: bool}>}
    public static function check(SpaBranch $branch): array
    {
        $branch->loadMissing('business');
        $hasOffer = self::hasOffer($branch);
        $hasStaff = self::workingTherapists($branch->staff())->exists();
        $polish = self::polish($branch, $hasOffer, $hasStaff);

        $items = [
            ['key' => 'branch_verified', 'label' => 'Branch verified and active',
                'done' => $branch->verification_status === 'Verified' && $branch->operating_status === 'Active'],
            ['key' => 'business_verified', 'label' => 'Business verified',
                'done' => $branch->business?->verification_status === 'Verified'],
            ['key' => 'location', 'label' => 'Map location set',
                'done' => $branch->latitude !== null && $branch->longitude !== null],
            ['key' => 'services', 'label' => 'At least one service or package available', 'done' => $hasOffer],
            ['key' => 'hours', 'label' => 'Opening hours set',
                'done' => self::openDays($branch->schedules())->exists()],
            ['key' => 'therapists', 'label' => 'At least one active staff member', 'done' => $hasStaff],
        ];

        // The Required listing items the policy adds. The two fixed ones are
        // the `services` and `therapists` rows above already.
        foreach ($polish as $item) {
            if ($item['required'] && ! $item['fixed']) {
                $items[] = ['key' => "polish_{$item['key']}", 'label' => $item['label'], 'done' => $item['done']];
            }
        }

        // Last: the owner's own switch.
        $items[] = ['key' => 'listed', 'label' => 'Shown on marketplace', 'done' => (bool) $branch->listing_visible];

        return [
            'ready' => collect($items)->every(fn ($i) => $i['done']),
            'items' => $items,
            'polish' => $polish,
        ];
    }

    private static function openDays($query)
    {
        return $query
            ->where('is_closed', false)
            ->whereNotNull('opening_time')
            ->whereNotNull('closing_time');
    }

    // A therapist with no schedule entered works whenever the branch is open
    // (BookingSlotService's convention); one whose rows are all days off
    // can't take anyone.
    private static function workingTherapists($query)
    {
        return $query
            ->where('role', 'therapist')
            ->where('status', 'active')
            ->where(fn ($q) => $q
                ->whereDoesntHave('schedules')
                ->orWhereHas('schedules', fn ($s) => $s->where('is_day_off', false)));
    }
}

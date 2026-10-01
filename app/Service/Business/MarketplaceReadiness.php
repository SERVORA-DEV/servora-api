<?php

namespace App\Service\Business;

use App\Models\SpaBranch;
use App\Repository\Business\SpaBranchRepository;
use App\Service\Client\BookingPolicy;
use Illuminate\Database\Eloquent\Builder;

// Whether a branch can actually take a booking from the client app, so the
// marketplace only lists branches a client won't dead-end at. Ready means:
//  - the business is verified (not pending or suspended);
//  - at least one service or package is on offer;
//  - opening hours are set for at least one day;
//  - at least one active therapist who works at least one day;
//  - online booking is switched on (BookingPolicy).
// On top of the public guard every listing already applies (branch Verified,
// Active, listed and pinned — see SpaBranchRepository).
//
// scope() is the SQL form used by browse lists; check() is the same rule,
// item by item, for the owner's checklist and the spa page. Online booking
// lives in settings JSON, so lists filter it with acceptsOnlineBooking().
class MarketplaceReadiness
{
    public const NOT_BOOKABLE = "This spa isn't taking bookings right now.";

    public static function scope(Builder $query): Builder
    {
        return $query
            ->whereHas('business', fn ($q) => $q->where('verification_status', 'Verified'))
            ->where(fn ($q) => $q
                ->whereHas('branchServices', fn ($s) => SpaBranchRepository::publicServices($s))
                ->orWhereHas('branchPackages', fn ($p) => SpaBranchRepository::publicPackages($p)))
            ->whereHas('schedules', fn ($q) => self::openDays($q))
            ->whereHas('staff', fn ($q) => self::workingTherapists($q));
    }

    // The whole rule for one branch — what booking and the time slots check.
    // (The spa page itself stays reachable through publicFindByUuid(), so a
    // client with an existing booking can still open it.)
    public static function isBookable(SpaBranch $branch): bool
    {
        return self::scope(SpaBranch::whereKey($branch->id))->exists()
            && self::acceptsOnlineBooking($branch);
    }

    public static function acceptsOnlineBooking(SpaBranch $branch): bool
    {
        return BookingPolicy::for($branch)['online_booking_enabled'];
    }

    // @return array{ready: bool, items: list<array{key: string, label: string, done: bool}>}
    public static function check(SpaBranch $branch): array
    {
        $branch->loadMissing('business');

        $items = [
            ['key' => 'branch_verified', 'label' => 'Branch verified and active',
                'done' => $branch->verification_status === 'Verified' && $branch->operating_status === 'Active'],
            ['key' => 'business_verified', 'label' => 'Business verified',
                'done' => $branch->business?->verification_status === 'Verified'],
            ['key' => 'location', 'label' => 'Map location set',
                'done' => $branch->latitude !== null && $branch->longitude !== null],
            ['key' => 'services', 'label' => 'At least one service or package available',
                'done' => SpaBranchRepository::publicServices($branch->branchServices())->exists()
                    || SpaBranchRepository::publicPackages($branch->branchPackages())->exists()],
            ['key' => 'hours', 'label' => 'Opening hours set',
                'done' => self::openDays($branch->schedules())->exists()],
            ['key' => 'therapists', 'label' => 'At least one active therapist',
                'done' => self::workingTherapists($branch->staff())->exists()],
            ['key' => 'online_booking', 'label' => 'Online booking turned on',
                'done' => self::acceptsOnlineBooking($branch)],
            ['key' => 'listed', 'label' => 'Shown on marketplace',
                'done' => (bool) $branch->listing_visible],
        ];

        return [
            'ready' => collect($items)->every(fn ($i) => $i['done']),
            'items' => $items,
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

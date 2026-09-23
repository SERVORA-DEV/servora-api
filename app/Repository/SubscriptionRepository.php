<?php

namespace App\Repository;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SubscriptionRepository
{
    public function paginate(int $perPage = 15)
    {
        return Subscription::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Subscription::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Subscription::where('uuid', $uuid)->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return Subscription::where($field, $value)->firstOrFail();
    }

    // The most recent subscription a business has ever had — active,
    // expired, or cancelled. "Current" means latest, not "active only",
    // so an expired/cancelled subscription still surfaces its own status
    // instead of silently falling back to "no subscription".
    public function findLatestForBusiness(int $spaBusinessId)
    {
        return Subscription::with('plan')
            ->where('spa_business_id', $spaBusinessId)
            ->latest()
            ->first();
    }

    // The subscription (if any) currently blocking this business from
    // starting a new one. Checked against expires_at rather than trusting
    // status === 'Active' alone — nothing flips a row to 'Expired'
    // automatically yet (no scheduled job exists), so a row that's still
    // marked Active in the DB but past its own expires_at must still be
    // treated as expired here, or a business could never resubscribe once
    // their term actually ends.
    public function findActiveForBusiness(int $spaBusinessId)
    {
        return Subscription::where('spa_business_id', $spaBusinessId)
            ->where('status', 'Active')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->latest()
            ->first();
    }

    // Same as findActiveForBusiness, but a lapsed subscription still counts
    // as active for $graceDays past its own expires_at — the grace-period
    // extension to real access (see EnsureBusinessSubscribed). Kept as its
    // own method rather than changing findActiveForBusiness in place, since
    // that one is also used where grace shouldn't apply (e.g. blocking a
    // duplicate active-subscription purchase).
    //
    // A subscription the owner declined to carry over to an updated plan
    // (plan_change_status = 'declined' — see PlanChangeService) gets no
    // grace: it ends exactly at expires_at, as the owner chose.
    public function findActiveOrInGraceForBusiness(int $spaBusinessId, int $graceDays)
    {
        return Subscription::where('spa_business_id', $spaBusinessId)
            ->where('status', 'Active')
            ->where(function ($query) use ($graceDays) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now())
                    ->orWhere(function ($query) use ($graceDays) {
                        $query->where('expires_at', '>', now()->subDays($graceDays))
                            ->where(fn ($q) => $this->notDeclined($q));
                    });
            })
            ->latest()
            ->first();
    }

    // Subscriptions due for a renewal reminder: each business's most recent
    // subscription only (same reasoning as countOverdue — a stale superseded
    // row shouldn't generate a reminder), still Active, not ending by the
    // owner's own choice, and expiring within the configured window.
    //
    // expiry_reminder_sent_at is the LAST reminder sent (stamped by
    // NotifyAlmostDueSubscriptions). With $repeat off it's a one-shot, as
    // before; with $repeat on, another reminder goes out every $everyDays,
    // and the window extends through the grace period so a lapsed owner
    // keeps getting "payment overdue" reminders until they pay (a payment
    // creates a new subscription row, which resets all of this). The hour of
    // slack stops a daily run from skipping a day because yesterday's send
    // happened a few seconds later than today's check.
    public function findDueForReminder(int $daysBefore, bool $repeat, int $everyDays, int $graceDays)
    {
        $latestIdsPerBusiness = Subscription::selectRaw('MAX(id) as id')
            ->groupBy('spa_business_id')
            ->pluck('id');

        $windowStart = $repeat ? now()->subDays($graceDays) : now();

        return Subscription::with(['business.owner', 'plan', 'pendingPlan'])
            ->whereIn('id', $latestIdsPerBusiness)
            ->where('status', 'Active')
            ->where(fn ($q) => $this->notDeclined($q))
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$windowStart, now()->addDays($daysBefore)])
            ->where(function ($query) use ($repeat, $everyDays) {
                $query->whereNull('expiry_reminder_sent_at');

                if ($repeat) {
                    $query->orWhere('expiry_reminder_sent_at', '<=', now()->subDays($everyDays)->addHour());
                }
            })
            ->get();
    }

    // Each business's latest subscription still on (or already moved to a
    // pending change from) the given plan, and still live or within grace —
    // the owners who need to hear that the plan was just changed.
    public function findLiveOnPlan(int $planId, int $graceDays)
    {
        $latestIdsPerBusiness = Subscription::selectRaw('MAX(id) as id')
            ->groupBy('spa_business_id')
            ->pluck('id');

        return Subscription::with(['business.owner', 'plan'])
            ->whereIn('id', $latestIdsPerBusiness)
            ->where('status', 'Active')
            ->where(function ($query) use ($planId) {
                $query->where('subscription_plan_id', $planId)
                    ->orWhere('pending_plan_id', $planId);
            })
            ->where(function ($query) use ($graceDays) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()->subDays($graceDays));
            })
            ->get();
    }

    private function notDeclined($query)
    {
        return $query->whereNull('plan_change_status')
            ->orWhere('plan_change_status', '!=', 'declined');
    }

    // How many businesses are currently lapsed: each business can have
    // several Subscription rows over time (a renewal creates a new row
    // rather than updating the old one — see activateSubscriptionFromPayment),
    // and old rows are never flipped away from 'Active', so this only looks
    // at each business's MOST RECENT subscription — otherwise a business
    // that already renewed would still be counted overdue via its stale,
    // superseded row.
    public function countOverdue(int $graceDays): int
    {
        $latestIdsPerBusiness = Subscription::selectRaw('MAX(id) as id')
            ->groupBy('spa_business_id')
            ->pluck('id');

        // A subscription the owner chose to let end isn't "overdue".
        return Subscription::whereIn('id', $latestIdsPerBusiness)
            ->where('status', 'Active')
            ->where(fn ($q) => $this->notDeclined($q))
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subDays($graceDays))
            ->count();
    }

    public function update(string $uuid, array $payload)
    {
        $model = $this->findByUuid($uuid);
        $model->update($payload);
        return $model;
    }

    public function delete(string $uuid)
    {
        $model = $this->findByUuid($uuid);
        return $model->delete();
    }

    public function restore(string $uuid)
    {
        $model = Subscription::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $model->restore();
        return $model;
    }
}
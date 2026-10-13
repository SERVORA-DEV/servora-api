<?php

namespace App\Service\System;

use App\Models\Billing;
use App\Models\Subscription;
use App\Models\SystemSetting;
use App\Support\SubscriberState;

// The administrator's Subscribers list: every business that has ever had a
// subscription, one row each, with where it stands right now (active, on
// trial, renewing soon, overdue, did not renew — App\Support\SubscriberState).
class SubscriberService
{
    public function list(): array
    {
        $graceDays = (int) SystemSetting::current()->subscription_grace_period_days;

        // Each business's most recent subscription row is its current one: a
        // renewal adds a row and old rows keep their status (the same rule as
        // SubscriptionRepository::countOverdue). A row still waiting for its
        // payment has not started, so it never stands in for the last real one.
        $latestIds = Subscription::where('status', '!=', 'Pending')
            ->selectRaw('MAX(id) as id')
            ->groupBy('spa_business_id')
            ->pluck('id');

        $subscriptions = Subscription::with(['plan', 'business.owner', 'paymentMethod'])
            ->whereIn('id', $latestIds)
            ->get()
            ->filter(fn (Subscription $s) => $s->business !== null);

        // Two grouped reads for the whole list, not one per row.
        $since = Subscription::where('is_trial', false)->where('status', '!=', 'Pending')
            ->selectRaw('spa_business_id, MIN(starts_at) as since')
            ->groupBy('spa_business_id')
            ->pluck('since', 'spa_business_id');
        $paid = Billing::where('billing_type', 'Subscription')->where('status', 'Paid')
            ->selectRaw('spa_business_id, COALESCE(SUM(amount), 0) as total, COUNT(*) as payments')
            ->groupBy('spa_business_id')
            ->get()
            ->keyBy('spa_business_id');

        $rows = $subscriptions->map(function (Subscription $s) use ($graceDays, $since, $paid) {
            $standing = SubscriberState::of(
                (bool) $s->is_trial,
                (string) $s->status,
                $s->expires_at,
                (bool) $s->auto_renew,
                $s->isEndingByChoice(),
                $s->renewal_failure_reason,
                $graceDays,
            );
            $owner = $s->business->owner;
            $first = $since[$s->spa_business_id] ?? null;

            return [
                'uuid' => $s->uuid,
                'business_uuid' => $s->business->uuid,
                'business_name' => $s->business->business_name,
                'owner_name' => trim(($owner?->first_name ?? '') . ' ' . ($owner?->last_name ?? '')) ?: null,
                'owner_email' => $owner?->email,
                'plan_name' => $s->plan?->name,
                'plan_category' => $s->plan?->category,
                'billing_cycle' => $s->is_trial ? null : $s->billing_cycle,
                'is_trial' => (bool) $s->is_trial,
                'state' => $standing['state'],
                'note' => $standing['note'],
                'starts_at' => optional($s->starts_at)->toIso8601String(),
                'expires_at' => optional($s->expires_at)->toIso8601String(),
                'auto_renew' => (bool) $s->auto_renew,
                'payment_method' => $s->auto_renew ? $s->paymentMethod?->label : null,
                'renewal_failure_reason' => $s->renewal_failure_reason,
                // When they first paid for a plan, and what they have paid in all.
                'subscribed_since' => $first ? \Illuminate\Support\Carbon::parse($first)->toIso8601String() : null,
                'total_paid' => (float) ($paid[$s->spa_business_id]->total ?? 0),
                'payments' => (int) ($paid[$s->spa_business_id]->payments ?? 0),
            ];
        })->values();

        // A count for every state (zero included), so the page's chips never
        // have to guess, and they always add up to the rows.
        $counts = array_fill_keys(SubscriberState::ALL, 0);
        foreach ($rows as $row) {
            $counts[$row['state']]++;
        }

        return [
            'data' => $rows,
            'counts' => ['all' => $rows->count()] + $counts,
            'grace_days' => $graceDays,
            'renewing_soon_days' => SubscriberState::RENEWING_SOON_DAYS,
        ];
    }
}

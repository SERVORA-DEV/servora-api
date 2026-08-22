<?php

namespace App\Repository;

use App\Models\Billing;
use Illuminate\Support\Str;

class BillingRepository
{
    public function create(array $payload)
    {
        return Billing::create($payload);
    }

    public function generateBillingNumber(): string
    {
        do {
            $number = 'BIL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (Billing::where('billing_number', $number)->exists());

        return $number;
    }

    // Billing history for the business owner's subscription page — newest
    // first, regardless of subscription status, so past invoices stay
    // visible even after a plan expires or is cancelled.
    public function findForBusiness(int $spaBusinessId, int $limit = 25)
    {
        return Billing::with('subscription.plan')
            ->where('spa_business_id', $spaBusinessId)
            ->orderByDesc('issued_at')
            ->limit($limit)
            ->get();
    }

    // Platform-wide subscription billing history for the system admin
    // "Transaction" page. Payments are eager-loaded newest-first so
    // TransactionResource can grab payments->first() as the latest attempt
    // (a billing can have more than one, e.g. a retry after a failed charge).
    public function paginateSubscriptionTransactions(int $perPage = 100)
    {
        return Billing::with([
            'subscription.plan',
            'subscription.business.owner',
            'payments' => fn ($query) => $query->latest('paid_at'),
        ])
            ->where('billing_type', 'Subscription')
            ->orderByDesc('issued_at')
            ->paginate($perPage);
    }

    // Top-N for the system dashboard's "Recent Transactions" card — same
    // eager-loads as paginateSubscriptionTransactions(), just limit()->get()
    // instead of paginate() since no page count is needed for a fixed card.
    public function recentSubscriptionTransactions(int $limit = 8)
    {
        return Billing::with([
            'subscription.plan',
            'subscription.business.owner',
            'payments' => fn ($query) => $query->latest('paid_at'),
        ])
            ->where('billing_type', 'Subscription')
            ->orderByDesc('issued_at')
            ->limit($limit)
            ->get();
    }
}

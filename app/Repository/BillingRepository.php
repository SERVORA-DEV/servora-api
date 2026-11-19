<?php

namespace App\Repository;

use App\Enums\BillingStatus;
use App\Enums\PaymentStatus;
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
    // visible even after a plan expires or is cancelled. Only what the owner paid
    // Servora: the bills for their own clients' appointments are the spa's takings,
    // not a subscription charge, and don't belong in this history or its totals.
    public function findForBusiness(int $spaBusinessId, int $limit = 25)
    {
        return Billing::with('subscription.plan')
            ->where('spa_business_id', $spaBusinessId)
            ->where('billing_type', 'Subscription')
            ->orderByDesc('issued_at')
            ->limit($limit)
            ->get();
    }

    // Platform-wide subscription billing history for the system admin
    // "Transaction" page. Payments are eager-loaded newest-first so
    // TransactionResource can grab payments->first() as the latest attempt
    // (a billing can have more than one, e.g. a retry after a failed charge).
    //
    // Filtered by the API (the admin Transaction page): search (invoice, business, owner, payment
    // or gateway reference), status (paid, pending, …), plan (its category), date_from / date_to
    // (the day it was paid), sort (newest, oldest, amount-desc, amount-asc; by payment date).
    public function paginateSubscriptionTransactions(int $perPage = 100, array $filters = [])
    {
        $sort = $filters['sort'] ?? null;

        return $this->subscriptionTransactions($filters)
            ->with([
                'subscription.plan',
                'subscription.business.owner',
                'payments' => fn ($query) => $query->latest('paid_at'),
            ])
            ->when($sort === null, fn ($q) => $q->orderByDesc('issued_at'))
            ->when($sort === 'newest', fn ($q) => $q->orderByRaw('paid_at desc nulls last'))
            ->when($sort === 'oldest', fn ($q) => $q->orderByRaw('paid_at asc nulls first'))
            ->when($sort === 'amount-desc', fn ($q) => $q->orderByDesc('amount'))
            ->when($sort === 'amount-asc', fn ($q) => $q->orderBy('amount'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** The subscription bills, with the Transaction page's filters. */
    public function subscriptionTransactions(array $filters = [])
    {
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';

        return Billing::query()
            ->where('billing_type', 'Subscription')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereLike('billing_number', "%{$search}%")
                ->orWhereHas('subscription.business', fn ($b) => $b->whereLike('business_name', "%{$search}%")
                    ->orWhereHas('owner', fn ($o) => $o->whereLike('email', "%{$search}%")
                        ->orWhereRaw("LOWER(COALESCE(first_name, '') || ' ' || COALESCE(last_name, '')) LIKE ?", ['%' . mb_strtolower($search) . '%'])))
                ->orWhereHas('payments', fn ($p) => $p->whereLike('reference_number', "%{$search}%")->orWhereLike('gateway_reference', "%{$search}%"))))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->whereRaw('LOWER(status) = ?', [strtolower($s)]))
            ->when($filters['plan'] ?? null, fn ($q, $p) => $q->whereHas('subscription.plan', fn ($pl) => $pl->where('category', $p)))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('paid_at', '>=', \Illuminate\Support\Carbon::parse($d)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('paid_at', '<=', \Illuminate\Support\Carbon::parse($d)->endOfDay()));
    }

    /**
     * The Transaction page's overview, over every subscription bill: revenue and how many were
     * paid, how many renewals (a subscription's bills after its first), and the bills per status.
     */
    public function subscriptionTransactionTotals(): array
    {
        $bills = Billing::query()->where('billing_type', 'Subscription');
        $row = (clone $bills)->toBase()->selectRaw(
            'count(*) as bills, count(distinct subscription_id) as subscriptions, sum(case when subscription_id is not null then 1 else 0 end) as linked,'
            . " coalesce(sum(case when status = '" . BillingStatus::Paid->value . "' then amount else 0 end), 0) as revenue,"
            . " sum(case when status = '" . BillingStatus::Paid->value . "' then 1 else 0 end) as paid"
        )->first();
        $byStatus = (clone $bills)->toBase()->groupBy('status')->selectRaw('LOWER(status) as s, count(*) as n')->pluck('n', 's');

        return [
            'total_revenue' => round((float) $row->revenue, 2),
            'paid_count' => (int) $row->paid,
            'renewals_count' => max(0, (int) $row->linked - (int) $row->subscriptions),
            'status_counts' => array_merge(['paid' => 0, 'pending' => 0, 'overdue' => 0, 'cancelled' => 0, 'refunded' => 0],
                collect($byStatus)->map(fn ($n) => (int) $n)->all()),
        ];
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

    // One appointment has at most one Billing row — enforced in
    // VisitBillingService::proceedToBilling (422 if one already exists), not
    // at the DB level, since appointment_id stays nullable/shared with
    // Subscription billing.
    public function findForAppointment(int $appointmentId)
    {
        return Billing::with('payments')
            ->where('appointment_id', $appointmentId)
            ->where('billing_type', 'Appointment')
            ->first();
    }

    // Backs the owner/manager "Billing & Payments" list page — every
    // appointment billing across the caller's branches, newest first, with
    // enough eager-loaded relations for BillingListResource to flatten
    // client/appointment/service info without N+1s. payment_method filters
    // by whether *any* payment on the billing used that method (a split
    // payment can span methods), mirroring how AppointmentRepository::
    // paginateForBranches structures its own optional filters.
    public function paginateAppointmentBillingsForBranches(array $spaBranchIds, array $filters, int $perPage = 15)
    {
        return Billing::with([
            'appointment.client',
            'appointment.services.serviceVariant.service',
            'branch',
            'payments' => fn ($query) => $query->latest('paid_at'),
        ])
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->where('billing_type', 'Appointment')
            // Status, method, dates and search: BillingListQuery, shared with the counts and totals.
            ->tap(fn ($q) => BillingListQuery::apply($q, $filters))
            // Latest issued first, or the oldest first.
            ->when(($filters['sort'] ?? null) === 'oldest', fn ($q) => $q->orderBy('issued_at')->orderBy('id'), fn ($q) => $q->orderByDesc('issued_at')->orderByDesc('id'))
            ->paginate($perPage);
    }
}

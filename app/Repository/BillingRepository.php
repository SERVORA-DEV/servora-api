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

    // One appointment has at most one Billing row — enforced in
    // AppointmentService::proceedToBilling (422 if one already exists), not
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
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['payment_method'] ?? null, fn ($q, $method) => $q->whereHas('payments', fn ($p) => $p->where('payment_method', $method)))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('issued_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('issued_at', '<=', $date))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($qq) => $qq
                ->where('billing_number', 'like', "%{$search}%")
                ->orWhereHas('appointment', fn ($a) => $a->where('appointment_number', 'like', "%{$search}%"))
                ->orWhereHas('appointment.client', fn ($c) => $c->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"))))
            ->orderByDesc('issued_at')
            ->paginate($perPage);
    }
}

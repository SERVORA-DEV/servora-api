<?php

namespace App\Repository;

use App\Models\Payment;

class PaymentRepository
{
    public function create(array $payload)
    {
        return Payment::create($payload);
    }

    public function findForBilling(int $billingId)
    {
        return Payment::where('billing_id', $billingId)->orderByDesc('created_at')->get();
    }

    // Sum of successfully-paid payments against a billing — used to decide
    // when a (possibly split/partial) payment sequence has fully covered
    // the bill. Refunded payments are excluded so a refund correctly
    // reopens the balance.
    public function paidTotalForBilling(int $billingId): float
    {
        return (float) Payment::where('billing_id', $billingId)
            ->where('payment_status', 'Paid')
            ->sum('amount');
    }
}

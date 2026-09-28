<?php

namespace App\Repository;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

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
    // the bill. Voided (entered by mistake) and Refunded payments don't
    // count, and money handed back on a Paid payment (refunded_amount) is
    // netted off.
    public function paidTotalForBilling(int $billingId): float
    {
        return (float) Payment::where('billing_id', $billingId)
            ->where('payment_status', 'Paid')
            ->sum(DB::raw('amount - COALESCE(refunded_amount, 0)'));
    }

    // Everything actually taken for a billing before any refunds — what a
    // refund can at most give back.
    public function grossPaidForBilling(int $billingId): float
    {
        return (float) Payment::where('billing_id', $billingId)
            ->where('payment_status', 'Paid')
            ->sum('amount');
    }
}

<?php

namespace App\Repository;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class PaymentRepository
{
    public function create(array $payload)
    {
        return Payment::create($payload);
    }

    // Sum of successfully-paid payments against a billing — used to decide
    // when a (possibly split/partial) payment sequence has fully covered
    // the bill. Voided (entered by mistake) and Refunded payments don't
    // count, and money handed back on a Paid payment (refunded_amount) is
    // netted off.
    public function paidTotalForBilling(int $billingId): float
    {
        return (float) Payment::where('billing_id', $billingId)
            ->where('payment_status', PaymentStatus::Paid->value)
            ->sum(DB::raw('amount - COALESCE(refunded_amount, 0)'));
    }
}

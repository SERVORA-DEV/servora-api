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
}

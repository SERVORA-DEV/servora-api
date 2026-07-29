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
}

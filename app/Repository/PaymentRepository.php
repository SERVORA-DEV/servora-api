<?php

namespace App\Repository;

use App\Models\Payment;

class PaymentRepository
{
    public function create(array $payload)
    {
        return Payment::create($payload);
    }
}

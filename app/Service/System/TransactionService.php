<?php

namespace App\Service\System;

use App\Repository\BillingRepository;
use App\Http\Resources\TransactionResource;

class TransactionService
{
    private BillingRepository $billingRepository;

    public function __construct(BillingRepository $billingRepository)
    {
        $this->billingRepository = $billingRepository;
    }

    public function listSubscriptionTransactions(int $perPage = 100)
    {
        $collection = $this->billingRepository->paginateSubscriptionTransactions($perPage);
        return TransactionResource::collection($collection);
    }
}

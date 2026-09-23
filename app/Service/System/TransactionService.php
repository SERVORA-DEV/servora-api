<?php

namespace App\Service\System;

use App\Repository\BillingRepository;
use App\Repository\SubscriptionRepository;
use App\Http\Resources\TransactionResource;
use App\Models\SystemSetting;

class TransactionService
{
    private BillingRepository $billingRepository;
    private SubscriptionRepository $subscriptionRepository;

    public function __construct(BillingRepository $billingRepository, SubscriptionRepository $subscriptionRepository)
    {
        $this->billingRepository = $billingRepository;
        $this->subscriptionRepository = $subscriptionRepository;
    }

    public function listSubscriptionTransactions(int $perPage = 100)
    {
        $collection = $this->billingRepository->paginateSubscriptionTransactions($perPage);

        // A billing row only ever exists once a payment has succeeded (see
        // SubscriptionService::activateSubscriptionFromPayment), so a lapsed
        // renewal never appears in $collection as an "overdue" row — Overdue
        // is a subscription-level count instead, alongside the transaction
        // list rather than derived from it.
        $gracePeriodDays = SystemSetting::current()->subscription_grace_period_days;

        return TransactionResource::collection($collection)->additional([
            'overdue_subscriptions_count' => $this->subscriptionRepository->countOverdue($gracePeriodDays),
            'subscription_grace_period_days' => $gracePeriodDays,
        ]);
    }
}

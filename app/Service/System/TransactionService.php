<?php

namespace App\Service\System;

use App\Repository\BillingRepository;
use App\Repository\SubscriptionRepository;
use App\Http\Resources\TransactionResource;
use App\Models\SystemSetting;

class TransactionService
{
    public function __construct(
        private BillingRepository $billingRepository,
        private SubscriptionRepository $subscriptionRepository,
    ) {}

    /** One page of subscription bills, filtered by the API, with the page's overview (`analytics`). */
    public function listSubscriptionTransactions(int $perPage = 100, array $filters = [])
    {
        $collection = $this->billingRepository->paginateSubscriptionTransactions($perPage, $filters);

        // A billing row only ever exists once a payment has succeeded (see
        // SubscriptionService::activateSubscriptionFromPayment), so a lapsed
        // renewal never appears in $collection as an "overdue" row — Overdue
        // is a subscription-level count instead, alongside the transaction
        // list rather than derived from it.
        $gracePeriodDays = SystemSetting::current()->subscription_grace_period_days;

        return TransactionResource::collection($collection)->additional([
            'overdue_subscriptions_count' => $this->subscriptionRepository->countOverdue($gracePeriodDays),
            'subscription_grace_period_days' => $gracePeriodDays,
            'analytics' => $this->billingRepository->subscriptionTransactionTotals(),
        ]);
    }
}

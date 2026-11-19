<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\TransactionListRequest;
use App\Service\System\TransactionService;
use App\Support\PerPage;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionService $transactionService,
    ) {}

    // One page of subscription bills, filtered by the API (BillingRepository::subscriptionTransactions).
    public function index(TransactionListRequest $request)
    {
        $filters = array_filter($request->only(['search', 'status', 'plan', 'date_from', 'date_to', 'sort']), fn ($v) => $v !== 'all');

        return $this->transactionService->listSubscriptionTransactions(PerPage::from($request), $filters);
    }
}

<?php

namespace App\Http\Controllers\System;

use Illuminate\Http\Request;
use App\Service\System\TransactionService;
use App\Http\Controllers\Controller;

class TransactionController extends Controller
{
    private TransactionService $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function index(Request $request)
    {
        return $this->transactionService->listSubscriptionTransactions($request->input('per_page', 100));
    }
}

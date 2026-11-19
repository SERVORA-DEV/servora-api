<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Service\Business\QueueService;
use Illuminate\Http\Request;

class QueueController extends Controller
{
    public function __construct(
        private QueueService $queueService,
    ) {}

    public function index(Request $request)
    {
        return $this->queueService->listForBranchToday($request->user(), $request->only(['date', 'status']));
    }
}

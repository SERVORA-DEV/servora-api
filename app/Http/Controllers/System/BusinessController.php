<?php

namespace App\Http\Controllers\System;

use App\Http\Requests\System\BusinessListRequest;
use App\Service\System\BusinessService;
use App\Http\Controllers\Controller;

class BusinessController extends Controller
{
    public function __construct(
        private BusinessService $businessService,
    ) {}

    // One page of businesses, filtered by the API (BusinessRepository::paginateAll).
    public function index(BusinessListRequest $request)
    {
        $filters = array_filter($request->only(['search', 'plan', 'status', 'sort']), fn ($v) => $v !== 'all');

        return $this->businessService->listBusinesses(\App\Support\PerPage::from($request), $filters);
    }

    public function show(string $uuid)
    {
        return $this->businessService->getBusiness($uuid);
    }

    public function programs(string $uuid)
    {
        return $this->businessService->programs($uuid);
    }
}

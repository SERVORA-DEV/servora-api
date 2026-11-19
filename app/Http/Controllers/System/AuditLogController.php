<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\AuditLogListRequest;
use App\Service\System\AuditLogService;
use App\Support\PerPage;

class AuditLogController extends Controller
{
    public function __construct(
        private AuditLogService $auditLogService,
    ) {}

    // One page of the administrators' audit trail, filtered by the API.
    public function index(AuditLogListRequest $request)
    {
        $filters = $request->only(['search', 'category', 'actor_id', 'action', 'module', 'date_from', 'date_to', 'sort']);
        if (($filters['category'] ?? null) === 'all') unset($filters['category']);

        return $this->auditLogService->listAuditLogs(PerPage::from($request, 20), $filters);
    }

    // The stat cards and category counts over the whole trail, and the filters' options.
    public function summary()
    {
        return response()->json(['data' => $this->auditLogService->summary()]);
    }
}

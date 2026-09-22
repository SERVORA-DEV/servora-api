<?php

namespace App\Http\Controllers\System;

use Illuminate\Http\Request;
use App\Service\System\AuditLogService;
use App\Http\Controllers\Controller;

class AuditLogController extends Controller
{
    private AuditLogService $auditLogService;

    public function __construct(AuditLogService $auditLogService)
    {
        $this->auditLogService = $auditLogService;
    }

    public function index(Request $request)
    {
        return $this->auditLogService->listAuditLogs($request->input('per_page', 100));
    }
}

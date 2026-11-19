<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\BusinessAuditLogListRequest;
use App\Service\Business\BusinessAuditLogService;

// Business → Audit Log (BusinessAuditLogService): the owner's whole business,
// or the branches a manager runs.
class BusinessAuditLogController extends Controller
{
    public function __construct(private BusinessAuditLogService $auditLog) {}

    public function index(BusinessAuditLogListRequest $request)
    {
        $filters = $request->validated();

        return $this->auditLog->list($request->user(), $filters);
    }
}

<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Service\Business\BusinessAuditLogService;
use Illuminate\Http\Request;

// Business → Audit Log (BusinessAuditLogService): the owner's whole business,
// or the branches a manager runs.
class BusinessAuditLogController extends Controller
{
    public function __construct(private BusinessAuditLogService $auditLog) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'branch' => 'nullable|uuid',
            'area' => 'nullable|string|max:20',
            'person' => 'nullable|uuid',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'q' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:10|max:200',
            'page' => 'nullable|integer|min:1',
        ]);

        return $this->auditLog->list($request->user(), $filters);
    }
}

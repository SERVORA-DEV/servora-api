<?php

namespace App\Service\System;

use App\Repository\AuditLogRepository;
use App\Http\Resources\AuditLogResource;

class AuditLogService
{
    private AuditLogRepository $auditLogRepository;

    public function __construct(AuditLogRepository $auditLogRepository)
    {
        $this->auditLogRepository = $auditLogRepository;
    }

    public function listAuditLogs(int $perPage = 100)
    {
        $collection = $this->auditLogRepository->paginate($perPage);
        return AuditLogResource::collection($collection);
    }
}

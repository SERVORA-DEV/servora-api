<?php

namespace App\Repository;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogRepository
{
    public function record(
        ?int $userId,
        string $tableName,
        ?int $recordId,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $userId,
            'table_name' => $tableName,
            'record_id' => $recordId,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    public function paginate(int $perPage = 15)
    {
        return AuditLog::with('user')->latest()->paginate($perPage);
    }
}

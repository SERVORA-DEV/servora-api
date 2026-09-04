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

    // Top-N for the system dashboard's "Audit Logs" card — a plain
    // limit()->get() rather than paginate() since no page count is needed.
    public function recent(int $limit = 8)
    {
        return AuditLog::with('user')->latest()->limit($limit)->get();
    }

    // Full change history for one record (e.g. an attendance row's "History"
    // section) — newest first, same limit-without-pagination shape as recent().
    public function forRecord(string $tableName, int $recordId, int $limit = 20)
    {
        return AuditLog::with('user.staff')
            ->where('table_name', $tableName)
            ->where('record_id', $recordId)
            ->latest()
            ->limit($limit)
            ->get();
    }
}

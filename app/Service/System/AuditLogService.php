<?php

namespace App\Service\System;

use App\Repository\AuditLogRepository;
use App\Http\Resources\AuditLogResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        $this->attachSubjects($collection->getCollection());

        return AuditLogResource::collection($collection);
    }

    // The name of the record each row acted on ("Wellness Spa", "Premium") —
    // one query per table for the whole page rather than one per row. Soft-
    // deleted records still resolve (plain DB queries ignore SoftDeletes);
    // anything unresolved falls back to the name stored in new_values.
    private function attachSubjects(Collection $logs): void
    {
        $lookups = [
            'spa_businesses' => fn (array $ids) => DB::table('spa_businesses')->whereIn('id', $ids)->pluck('business_name', 'id'),
            'spa_branches' => fn (array $ids) => DB::table('spa_branches')->whereIn('id', $ids)->pluck('branch_name', 'id'),
            'subscription_plans' => fn (array $ids) => DB::table('subscription_plans')->whereIn('id', $ids)->pluck('name', 'id'),
            'service_templates' => fn (array $ids) => DB::table('services')->whereIn('id', $ids)->pluck('name', 'id'),
            'owner_identity_verifications' => fn (array $ids) => DB::table('owner_identity_verifications')
                ->join('users', 'users.id', '=', 'owner_identity_verifications.user_id')
                ->whereIn('owner_identity_verifications.id', $ids)
                ->selectRaw("owner_identity_verifications.id, trim(concat(coalesce(users.first_name, ''), ' ', coalesce(users.last_name, ''))) as label")
                ->pluck('label', 'id'),
            'users' => fn (array $ids) => DB::table('users')->whereIn('id', $ids)
                ->selectRaw("id, coalesce(nullif(trim(concat(coalesce(first_name, ''), ' ', coalesce(last_name, ''))), ''), email) as label")
                ->pluck('label', 'id'),
        ];

        $names = [];
        foreach ($logs->groupBy('table_name') as $table => $rows) {
            $ids = $rows->pluck('record_id')->filter()->unique()->values()->all();
            if ($ids && isset($lookups[$table])) {
                $names[$table] = $lookups[$table]($ids);
            }
        }

        foreach ($logs as $log) {
            $isOwnAccount = $log->table_name === 'users' && $log->record_id === $log->user_id;

            $log->setAttribute('subject_label', $isOwnAccount
                ? null
                : (($names[$log->table_name][$log->record_id] ?? null) ?: ($log->new_values['name'] ?? null)));
        }
    }
}

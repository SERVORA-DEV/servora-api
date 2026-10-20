<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AuditLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',

        'table_name',
        'record_id',

        'action',

        'old_values',
        'new_values',

        'ip_address',
        'user_agent',

        // The business's Audit Log (see the 2026_10_28 migration): where it
        // happened, and its own copy of the names.
        'spa_business_id',
        'spa_branch_id',
        'event',
        'area',
        'summary',
        'subject_label',
        'actor_name',
        'actor_role',
        'actor_level',
        'branch_name',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    /** Whether the business Audit Log's columns exist yet (the migration has been run). */
    public static function businessReady(): bool
    {
        $known = Cache::get('schema:audit_logs_business');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('audit_logs', 'spa_business_id');
        $ready ? Cache::forever('schema:audit_logs_business', true) : Cache::put('schema:audit_logs_business', false, 60);

        return $ready;
    }
}

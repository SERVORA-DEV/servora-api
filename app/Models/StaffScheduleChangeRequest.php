<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// A manager's proposed weekly schedule for a staff member, waiting for the
// owner's decision — see StaffScheduleService.
class StaffScheduleChangeRequest extends Model
{
    use HasUuids;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';
    // Replaced by a newer request, or overtaken by the owner's own edit.
    public const SUPERSEDED = 'superseded';

    protected $fillable = [
        'uuid',
        'staff_id',
        'spa_business_id',
        'spa_branch_id',
        'requested_by_user_id',
        'days',
        'status',
        'decided_by_user_id',
        'decided_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}

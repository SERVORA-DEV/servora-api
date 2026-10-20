<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// A manager's request to move a staff member to another branch, waiting for
// the owner's decision — see App\Service\Business\StaffTransferService.
class StaffTransferRequest extends Model
{
    use HasUuids;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const WITHDRAWN = 'withdrawn';
    // Replaced by a newer request, or overtaken by the owner's own move.
    public const SUPERSEDED = 'superseded';

    protected $fillable = [
        'uuid',
        'staff_id',
        'spa_business_id',
        'from_branch_id',
        'to_branch_id',
        'from_branch_name',
        'to_branch_name',
        'requested_by_user_id',
        'status',
        'decided_by_user_id',
        'decided_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
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

    public function fromBranch()
    {
        return $this->belongsTo(SpaBranch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(SpaBranch::class, 'to_branch_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * Whether staff transfers exist yet (the migration has been run). Until
     * then the Branch field on an employee changes the branch outright, as it
     * did before.
     */
    public static function ready(): bool
    {
        $known = Cache::get('schema:staff_transfers');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasTable('staff_transfer_requests') && Schema::hasColumn('attendances', 'spa_branch_id');
        $ready ? Cache::forever('schema:staff_transfers', true) : Cache::put('schema:staff_transfers', false, 60);

        return $ready;
    }
}

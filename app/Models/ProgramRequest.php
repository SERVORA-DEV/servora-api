<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// A manager's request for a new customer program for their branch, waiting
// for the owner — see ProgramRequestService.
class ProgramRequest extends Model
{
    use HasUuids;

    public const PENDING = 'pending';
    // The owner approved it: the program exists (customer_program_id).
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const WITHDRAWN = 'withdrawn';

    // Loyalty is one program for the whole business, so it isn't asked for.
    public const TYPES = ['voucher', 'discount', 'membership'];

    protected $fillable = [
        'uuid',
        'spa_business_id',
        'spa_branch_id',
        'requested_by_user_id',
        'type',
        'name',
        'payload',
        'note',
        'status',
        'decided_by_user_id',
        'decided_at',
        'decision_note',
        'customer_program_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function program()
    {
        return $this->belongsTo(CustomerProgram::class, 'customer_program_id')->withTrashed();
    }

    /**
     * Whether the table and customer_programs.only_branch_id exist yet (the
     * migration has been run). Until then the feature stays out of the way.
     */
    public static function ready(): bool
    {
        // Asked on every programs read, so both answers are remembered: "yes"
        // for good, "not yet" for a minute so running the migration is
        // noticed without looking at the schema on each request.
        $known = Cache::get('schema:program_requests');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasTable('program_requests') && Schema::hasColumn('customer_programs', 'only_branch_id');
        $ready ? Cache::forever('schema:program_requests', true) : Cache::put('schema:program_requests', false, 60);

        return $ready;
    }
}

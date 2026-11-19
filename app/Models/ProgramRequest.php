<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// A manager's request for a new customer program for their branch, waiting
// for the owner — see ProgramRequestService.
//
// Kept in approval_requests with the other requests a manager makes of the owner
// (subject 'program'); this model sees only its own rows and marks the ones it makes.
class ProgramRequest extends Model
{
    use HasUuids;

    public const SUBJECT = 'program';

    // Loyalty is one program for the whole business, so it isn't asked for.
    public const TYPES = ['voucher', 'discount', 'membership'];

    protected $table = 'approval_requests';

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

    protected static function booted(): void
    {
        static::addGlobalScope('subject', fn (Builder $q) => $q->where('approval_requests.subject', self::SUBJECT));
        static::creating(fn (self $request) => $request->setAttribute('subject', self::SUBJECT));
    }

    /** Which program is asked for (voucher, discount, membership), kept in the shared `kind` column. */
    protected function type(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => $attributes['kind'] ?? null,
            set: fn ($value) => ['kind' => $value],
        );
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
        $known = Cache::get('schema:approval_requests');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasTable('approval_requests') && Schema::hasColumn('customer_programs', 'only_branch_id');
        $ready ? Cache::forever('schema:approval_requests', true) : Cache::put('schema:approval_requests', false, 60);

        return $ready;
    }
}

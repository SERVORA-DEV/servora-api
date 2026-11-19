<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// A manager's request for a service or package the catalog doesn't have,
// waiting for the owner — see CatalogRequestService.
//
// Kept in approval_requests with the other requests a manager makes of the owner
// (subject 'catalog'); this model sees only its own rows and marks the ones it makes.
class CatalogRequest extends Model
{
    use HasUuids;

    public const SUBJECT = 'catalog';

    protected $table = 'approval_requests';

    protected $fillable = [
        'uuid',
        'spa_business_id',
        'spa_branch_id',
        'requested_by_user_id',
        'kind',
        'name',
        'category',
        'details',
        // The form the manager filled in.
        'payload',
        'status',
        'decided_by_user_id',
        'decided_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('subject', fn (Builder $q) => $q->where('approval_requests.subject', self::SUBJECT));
        static::creating(fn (self $request) => $request->setAttribute('subject', self::SUBJECT));
    }

    /** The manager's note, kept in the shared `note` column. */
    protected function details(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => $attributes['note'] ?? null,
            set: fn ($value) => ['note' => $value],
        );
    }

    // The form a request carries. (Was a check that the payload column had been added; it is
    // always there in approval_requests. Kept until the old tables are dropped.)
    public static function hasPayload(): bool
    {
        return true;
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
}

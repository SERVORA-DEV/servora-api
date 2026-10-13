<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// A manager's request for a service or package the catalog doesn't have,
// waiting for the owner — see CatalogRequestService.
class CatalogRequest extends Model
{
    use HasUuids;

    public const PENDING = 'pending';
    // The owner added it to the catalog.
    public const ADDED = 'added';
    public const DECLINED = 'declined';
    public const WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'uuid',
        'spa_business_id',
        'spa_branch_id',
        'requested_by_user_id',
        'kind',
        'name',
        'category',
        'details',
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

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}

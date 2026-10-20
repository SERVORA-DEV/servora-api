<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

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
        // The form the manager filled in (see the migration that adds it).
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

    // Whether the payload column exists yet (the migration has been run).
    // Until then a request is only a name and a note, as before.
    public static function hasPayload(): bool
    {
        $known = Cache::get('schema:catalog_request_payload');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('catalog_requests', 'payload');
        $ready ? Cache::forever('schema:catalog_request_payload', true) : Cache::put('schema:catalog_request_payload', false, 60);

        return $ready;
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

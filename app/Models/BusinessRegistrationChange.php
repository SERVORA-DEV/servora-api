<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// An owner's request to change the company registration of a verified
// business (BusinessRegistrationChangeService). Nothing on the business
// changes until a system administrator approves it.
class BusinessRegistrationChange extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid', 'spa_business_id', 'user_id',
        'registration_document_type', 'registration_number', 'registered_business_name', 'registered_owner_name',
        'registration_document_path', 'note', 'previous',
        'status', 'rejection_reason', 'reviewed_by', 'submitted_at', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'previous' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function business()
    {
        return $this->belongsTo(SpaBusiness::class, 'spa_business_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

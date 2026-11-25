<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// An owner's review of SERVORA, public at submission unless hidden by an administrator.
class ServoraReview extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid', 'user_id', 'spa_business_id', 'rating', 'title', 'body', 'show_business',
        'status', 'reviewed_by', 'reviewed_at', 'published_at',
        'pending_rating', 'pending_title', 'pending_body', 'pending_show_business', 'pending_at', 'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'show_business' => 'boolean',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
            'pending_rating' => 'integer',
            'pending_show_business' => 'boolean',
            'pending_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    /** An edit of the published review is waiting for a check. */
    public function hasPendingEdit(): bool
    {
        return $this->pending_at !== null;
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function business()
    {
        return $this->belongsTo(SpaBusiness::class, 'spa_business_id');
    }
}

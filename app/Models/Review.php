<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// A client's rating of a completed appointment. Read by the owner's
// Branch Settings → Marketplace → Reviews summary.
class Review extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['uuid', 'appointment_id', 'client_id', 'rating', 'comment', 'is_anonymous', 'status', 'reviewed_at'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'is_anonymous' => 'boolean', 'reviewed_at' => 'datetime'];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}

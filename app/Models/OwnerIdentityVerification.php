<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OwnerIdentityVerification extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'user_id',

        'id_type',
        'id_document_front_path',
        'id_document_back_path',

        'face_scan_paths',
        'liveness_sequence',
        'liveness_result',
        'face_match_result',

        'status',
        'rejected_field',
        'rejection_reason',

        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'face_scan_paths' => 'array',
            'liveness_sequence' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}

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

    /**
     * ID types that are one photo, not two: a passport's data page is the
     * whole identification, so it has no back to photograph.
     */
    public const SINGLE_SIDED_ID_TYPES = ['Passport'];

    public static function backRequired(?string $idType): bool
    {
        return ! in_array($idType, self::SINGLE_SIDED_ID_TYPES, true);
    }

    /**
     * Whether every photo of the ID this type needs is on file.
     */
    public function hasIdDocuments(): bool
    {
        return (bool) $this->id_document_front_path
            && (! self::backRequired($this->id_type) || (bool) $this->id_document_back_path);
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

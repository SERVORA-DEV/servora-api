<?php

namespace App\Models;

use App\Enums\ChangeRequestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// An owner's request to change the name or email on their verified account
// (OwnerIdentityChangeService). Nothing on the account changes until a system
// administrator approves it.
class OwnerIdentityChange extends Model
{
    use HasUuids;

    public const OPEN = [ChangeRequestStatus::Draft->value, ChangeRequestStatus::Pending->value];

    protected $fillable = [
        'uuid', 'user_id',
        'first_name', 'middle_name', 'last_name', 'suffix', 'email',
        'email_code_hash', 'email_code_sent_at', 'email_confirmed_at',
        'id_type', 'id_document_front_path', 'id_document_back_path',
        'face_scan_paths', 'liveness_sequence',
        'status', 'rejection_reason', 'reviewed_by', 'submitted_at', 'reviewed_at',
    ];

    protected $hidden = ['email_code_hash'];

    protected function casts(): array
    {
        return [
            'face_scan_paths' => 'array',
            'liveness_sequence' => 'array',
            'email_code_sent_at' => 'datetime',
            'email_confirmed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
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

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The new email needs proving: it differs from the account's. */
    public function emailChanges(): bool
    {
        return mb_strtolower($this->email) !== mb_strtolower((string) $this->user?->email);
    }

    public function hasIdDocuments(): bool
    {
        return (bool) $this->id_document_front_path
            && (! OwnerIdentityVerification::backRequired($this->id_type) || (bool) $this->id_document_back_path);
    }
}

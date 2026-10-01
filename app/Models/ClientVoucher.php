<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// A voucher in a client's wallet — earned (spend / visits / first visit /
// birthday), bought with points, or bundled in a membership.
class ClientVoucher extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'client_id',
        'customer_program_id',
        'source',
        'source_billing_id',
        'client_membership_id',
        'issued_at',
        'expires_at',
        'status',
        'used_at',
        'used_billing_id',
        'used_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function program()
    {
        return $this->belongsTo(CustomerProgram::class, 'customer_program_id')->withTrashed();
    }

    public function isUsable(): bool
    {
        return $this->status === 'available' && (! $this->expires_at || $this->expires_at->isFuture());
    }
}

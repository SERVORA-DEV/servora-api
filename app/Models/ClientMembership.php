<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// A membership sold to a client at the front desk. Lasts one billing period
// (starts_at → ends_at); renewing starts a new row.
class ClientMembership extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'client_id',
        'customer_program_id',
        'spa_branch_id',
        'billing_id',
        'starts_at',
        'ends_at',
        'status',
        'price',
        'sold_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'price' => 'decimal:2',
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

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    public function isCurrent(): bool
    {
        return $this->status === 'active' && $this->ends_at->isFuture();
    }
}

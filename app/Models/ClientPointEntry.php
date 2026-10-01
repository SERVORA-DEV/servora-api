<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// The points ledger. `earn` rows keep `remaining` so redemptions and expiry
// spend the oldest points first; every other type just records the change.
// clients.current_points / lifetime_points are the running totals.
class ClientPointEntry extends Model
{
    protected $fillable = [
        'client_id',
        'billing_id',
        'type',
        'points',
        'remaining',
        'expires_at',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'remaining' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}

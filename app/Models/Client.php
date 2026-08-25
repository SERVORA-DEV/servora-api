<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'clients';

    // Simple operational attributes only — a walk-in with no account never
    // needs more than this to be identified/contacted. Full profile detail
    // (middle name, suffix, gender, birth date, avatar) lives on `users` for
    // anyone who has a Servora account and is read through user(), not
    // duplicated here — see ClientResource's `account` block.
    protected $fillable = [
        'uuid',
        'spa_business_id',
        'user_id',
        'first_name',
        'last_name',
        'phone_number',
        'email',
        'current_points',
        'lifetime_points',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'client_id');
    }
}

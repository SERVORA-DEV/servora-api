<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientFavorite extends Model
{
    protected $table = 'client_favorites';

    protected $fillable = ['user_id', 'spa_branch_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }
}

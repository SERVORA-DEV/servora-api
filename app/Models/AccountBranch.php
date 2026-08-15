<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Which branch a Manager/Front Officer account (a User) operates at — see
// AccountRepository::assignBranch. unique(user_id) on the table caps this
// at one branch per account.
class AccountBranch extends Model
{
    protected $fillable = [
        'user_id',
        'spa_branch_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }
}

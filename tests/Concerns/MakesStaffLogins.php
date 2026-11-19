<?php

namespace Tests\Concerns;

use App\Models\AccountBranch;
use App\Models\SpaBranch;
use App\Models\Staff;
use App\Models\User;

// A Manager or Front Officer login for a staff member, made the way the owner makes one
// (POST /business/account, as whoever the test is acting as — the owner). A login is given
// branches and a level there (AccountBranch): a manager runs their branch (management), anyone
// else works its front desk (operation). The login's role follows from that level.
trait MakesStaffLogins
{
    protected function makeStaffLogin(Staff $staff, string $username, string $email): User
    {
        $branch = SpaBranch::findOrFail($staff->spa_branch_id);
        $this->postJson('/api/business/account', [
            'branches' => [[
                'branch_uuid' => $branch->uuid,
                'level' => $staff->role === 'manager' ? AccountBranch::MANAGEMENT : AccountBranch::OPERATION,
            ]],
            'first_name' => $staff->first_name,
            'last_name' => $staff->last_name,
            'username' => $username,
            'email' => $email,
            'password' => 'Secret-password1',
        ])->assertSuccessful();

        return User::where('username', $username)->firstOrFail();
    }
}

<?php

namespace Tests\Unit;

use App\Models\AccountBranch;
use PHPUnit\Framework\TestCase;

class AccountScopeTest extends TestCase
{
    public function test_a_login_is_a_manager_when_it_has_management_anywhere(): void
    {
        $this->assertSame('manager', AccountBranch::roleFor(['management']));
        $this->assertSame('manager', AccountBranch::roleFor(['operation', 'management']));
        $this->assertSame('front_officer', AccountBranch::roleFor(['operation']));
        $this->assertSame('front_officer', AccountBranch::roleFor(['operation', 'operation']));
        $this->assertSame('front_officer', AccountBranch::roleFor([]));
    }

    // Management + Operation ('management', as a Manager login always had),
    // Management alone ('manage_only') or the front desk alone ('operation').
    public function test_management_alone_is_a_manager_without_the_front_desk(): void
    {
        $this->assertSame('manager', AccountBranch::roleFor(['manage_only']));
        $this->assertSame('manager', AccountBranch::roleFor(['operation', 'manage_only']));
        $this->assertContains('manage_only', AccountBranch::LEVELS);

        $this->assertTrue(AccountBranch::runsBranch('manage_only'));
        $this->assertTrue(AccountBranch::runsBranch(['operation', 'management']));
        $this->assertFalse(AccountBranch::runsBranch('operation'));
        $this->assertFalse(AccountBranch::runsBranch(null));

        $this->assertFalse(AccountBranch::hasFrontDesk('manage_only'));
        $this->assertTrue(AccountBranch::hasFrontDesk('management'));
        $this->assertTrue(AccountBranch::hasFrontDesk('operation'));
    }

    public function test_the_level_fits_its_column(): void
    {
        foreach (AccountBranch::LEVELS as $level) {
            $this->assertLessThanOrEqual(12, strlen($level), $level);   // account_branches.level is string(12)
        }
    }
}

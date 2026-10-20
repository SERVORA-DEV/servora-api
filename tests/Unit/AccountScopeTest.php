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
}

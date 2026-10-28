<?php

namespace Tests\Unit;

use App\Support\AdminAccess;
use PHPUnit\Framework\TestCase;

class AdminAccessTest extends TestCase
{
    public function test_with_no_list_every_address_may_reach_the_admin_door(): void
    {
        $this->assertTrue(AdminAccess::ipAllowed('198.51.100.9', []));
        $this->assertTrue(AdminAccess::ipAllowed(null, []));
    }

    public function test_a_listed_address_is_let_in_and_any_other_is_not(): void
    {
        $allowed = ['203.0.113.7'];

        $this->assertTrue(AdminAccess::ipAllowed('203.0.113.7', $allowed));
        $this->assertFalse(AdminAccess::ipAllowed('203.0.113.8', $allowed));
    }

    public function test_a_range_covers_every_address_inside_it(): void
    {
        $allowed = ['10.0.0.0/8', '203.0.113.7'];

        $this->assertTrue(AdminAccess::ipAllowed('10.20.30.40', $allowed));
        $this->assertTrue(AdminAccess::ipAllowed('203.0.113.7', $allowed));
        $this->assertFalse(AdminAccess::ipAllowed('11.0.0.1', $allowed));
    }

    public function test_ipv6_addresses_and_ranges_work_too(): void
    {
        $this->assertTrue(AdminAccess::ipAllowed('2001:db8::1', ['2001:db8::/32']));
        $this->assertFalse(AdminAccess::ipAllowed('2001:db9::1', ['2001:db8::/32']));
    }

    public function test_an_unknown_address_is_refused_once_a_list_is_set(): void
    {
        $this->assertFalse(AdminAccess::ipAllowed(null, ['203.0.113.7']));
        $this->assertFalse(AdminAccess::ipAllowed('', ['203.0.113.7']));
    }

    public function test_an_administrator_without_two_factor_is_turned_back(): void
    {
        $this->assertTrue(AdminAccess::twoFactorBlocks(true, 'system_administrator', false, false));
    }

    public function test_an_administrator_without_two_factor_can_still_reach_the_setup_pages(): void
    {
        $this->assertFalse(AdminAccess::twoFactorBlocks(true, 'system_administrator', false, true));
    }

    public function test_an_administrator_with_two_factor_is_never_turned_back(): void
    {
        $this->assertFalse(AdminAccess::twoFactorBlocks(true, 'system_administrator', true, false));
    }

    public function test_switching_the_requirement_off_lets_everyone_through(): void
    {
        $this->assertFalse(AdminAccess::twoFactorBlocks(false, 'system_administrator', false, false));
    }

    public function test_only_administrators_are_held_to_it(): void
    {
        foreach (['business_owner', 'manager', 'front_officer', 'client', null] as $role) {
            $this->assertFalse(AdminAccess::twoFactorBlocks(true, $role, false, false), (string) $role);
        }
    }
}

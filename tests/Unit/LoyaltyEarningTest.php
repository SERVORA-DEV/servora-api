<?php

namespace Tests\Unit;

use App\Support\LoyaltyEarning;
use PHPUnit\Framework\TestCase;

// Loyalty points are for clients who use the app.
class LoyaltyEarningTest extends TestCase
{
    public function test_a_client_who_uses_the_app_earns_points(): void
    {
        $this->assertTrue(LoyaltyEarning::earns(true));
    }

    public function test_a_client_without_the_app_earns_none(): void
    {
        $this->assertFalse(LoyaltyEarning::earns(false));
    }

    public function test_the_whole_balance_comes_off_a_client_without_the_app(): void
    {
        $this->assertSame(120, LoyaltyEarning::toRemove(false, 120));
        $this->assertSame(1, LoyaltyEarning::toRemove(false, 1));
    }

    public function test_an_app_user_keeps_every_point(): void
    {
        $this->assertSame(0, LoyaltyEarning::toRemove(true, 120));
        $this->assertSame(0, LoyaltyEarning::toRemove(true, 0));
    }

    public function test_there_is_nothing_to_take_from_an_empty_balance(): void
    {
        $this->assertSame(0, LoyaltyEarning::toRemove(false, 0));
        // A balance can't be below zero, and nothing is ever added by this.
        $this->assertSame(0, LoyaltyEarning::toRemove(false, -5));
    }

    public function test_the_history_line_says_why(): void
    {
        $this->assertSame('remove', LoyaltyEarning::REMOVED_TYPE);
        $this->assertLessThanOrEqual(10, strlen(LoyaltyEarning::REMOVED_TYPE), 'client_point_entries.type holds 10 characters');
        $this->assertStringContainsString('app users', LoyaltyEarning::REMOVED_NOTE);
    }
}

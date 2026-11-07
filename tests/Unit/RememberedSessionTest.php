<?php

namespace Tests\Unit;

use App\Support\RememberedSession;
use App\Support\SessionActivity;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

// "Remember me": a sign-in that keeps the device signed in.
class RememberedSessionTest extends TestCase
{
    private function now(): Carbon
    {
        return Carbon::parse('2026-11-07 10:00:00');
    }

    public function test_a_remembered_sign_in_lasts_a_year(): void
    {
        $this->assertSame('2027-11-07 10:00:00', RememberedSession::expiresAt($this->now())->format('Y-m-d H:i:s'));
    }

    public function test_only_a_token_with_its_own_end_date_is_remembered(): void
    {
        $this->assertTrue(RememberedSession::isRemembered('2027-11-07 10:00:00'));
        $this->assertFalse(RememberedSession::isRemembered(null));
    }

    public function test_it_works_until_its_end_date(): void
    {
        $this->assertTrue(RememberedSession::valid('2026-11-07 10:00:01', $this->now()));
        $this->assertFalse(RememberedSession::valid('2026-11-07 10:00:00', $this->now()));
        $this->assertFalse(RememberedSession::valid('2026-10-01 10:00:00', $this->now()));
    }

    public function test_using_it_moves_the_end_date_at_most_once_a_day(): void
    {
        // Issued or extended just now: nothing to write.
        $this->assertFalse(RememberedSession::shouldExtend('2027-11-07 10:00:00', $this->now()));
        // Extended 23 hours ago: still nothing.
        $this->assertFalse(RememberedSession::shouldExtend('2027-11-06 11:00:00', $this->now()));
        // Last extended over a day ago: move it.
        $this->assertTrue(RememberedSession::shouldExtend('2027-11-06 09:00:00', $this->now()));
        $this->assertTrue(RememberedSession::shouldExtend('2027-02-01 09:00:00', $this->now()));
    }

    public function test_a_remembered_sign_in_outlives_the_thirty_days_of_a_plain_one(): void
    {
        $month = 43200; // 30 days, in minutes

        // Signed in with Remember me three months ago, used since: still a session.
        $this->assertFalse(SessionActivity::expired('2026-08-01 09:00:00', '2027-11-01 09:00:00', $this->now(), $month));
        // A plain sign-in of the same age is gone.
        $this->assertTrue(SessionActivity::expired('2026-08-01 09:00:00', null, $this->now(), $month));
        // A remembered one not used for a year is gone too.
        $this->assertTrue(SessionActivity::expired('2025-08-01 09:00:00', '2026-11-01 09:00:00', $this->now(), $month));
    }
}

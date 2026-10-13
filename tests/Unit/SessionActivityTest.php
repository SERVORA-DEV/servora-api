<?php

namespace Tests\Unit;

use App\Support\SessionActivity;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

// Which sign-ins are sessions in use, which are only left behind.
class SessionActivityTest extends TestCase
{
    private function now(): Carbon
    {
        return Carbon::parse('2026-10-13 21:30:00');
    }

    public function test_a_session_used_in_the_last_half_hour_is_active(): void
    {
        $this->assertTrue(SessionActivity::active('2026-10-13 21:29:00', '2026-10-13 09:00:00', $this->now()));
        $this->assertTrue(SessionActivity::active('2026-10-13 21:01:00', '2026-10-01 09:00:00', $this->now()));
    }

    public function test_a_browser_closed_hours_ago_is_idle_however_recently_it_signed_in(): void
    {
        // Signed in this morning, last asked for anything at 11:53.
        $this->assertFalse(SessionActivity::active('2026-10-13 11:53:07', '2026-10-13 11:45:55', $this->now()));
        // Exactly at the edge counts as idle.
        $this->assertFalse(SessionActivity::active('2026-10-13 21:00:00', '2026-10-13 09:00:00', $this->now()));
    }

    public function test_a_sign_in_not_used_yet_goes_by_when_it_signed_in(): void
    {
        $this->assertTrue(SessionActivity::active(null, '2026-10-13 21:25:00', $this->now()));
        $this->assertFalse(SessionActivity::active(null, '2026-10-13 12:00:00', $this->now()));
        $this->assertFalse(SessionActivity::active(null, null, $this->now()));
    }

    public function test_a_token_past_its_lifetime_no_longer_counts_at_all(): void
    {
        $month = 43200; // 30 days, in minutes

        $this->assertTrue(SessionActivity::expired('2026-09-10 09:00:00', null, $this->now(), $month));
        $this->assertFalse(SessionActivity::expired('2026-10-02 11:46:38', null, $this->now(), $month));
        // Its own end date wins.
        $this->assertTrue(SessionActivity::expired('2026-10-13 09:00:00', '2026-10-13 12:00:00', $this->now(), $month));
        // No lifetime set: tokens don't expire by age.
        $this->assertFalse(SessionActivity::expired('2025-01-01 09:00:00', null, $this->now(), null));
    }
}

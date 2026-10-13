<?php

namespace Tests\Unit;

use App\Support\SubscriberState;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

// Where a subscription stands, from its dates and flags alone. No database.
class SubscriberStateTest extends TestCase
{
    private const GRACE = 7;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-10-07 12:00:00');
    }

    private function state(array $with = []): array
    {
        $s = $with + ['trial' => false, 'status' => 'Active', 'ends' => 30, 'auto' => false, 'byChoice' => false, 'failure' => null];

        return SubscriberState::of(
            $s['trial'], $s['status'],
            $s['ends'] === null ? null : $this->now->copy()->addDays($s['ends']),
            $s['auto'], $s['byChoice'], $s['failure'], self::GRACE, $this->now,
        );
    }

    public function test_a_paid_plan_with_plenty_of_time_left_is_active(): void
    {
        $this->assertSame('active', $this->state(['ends' => 30])['state']);
        $this->assertSame('active', $this->state(['ends' => null])['state']);
        $this->assertSame('Renews automatically', $this->state(['ends' => 30, 'auto' => true])['note']);
    }

    public function test_a_running_trial_is_on_trial_however_close_its_end(): void
    {
        $this->assertSame('trial', $this->state(['trial' => true, 'ends' => 10])['state']);
        $this->assertSame('trial', $this->state(['trial' => true, 'ends' => 1])['state']);
    }

    public function test_a_paid_plan_ending_within_fourteen_days_is_renewing_soon(): void
    {
        $this->assertSame('renewing_soon', $this->state(['ends' => 14])['state']);
        $this->assertSame('active', $this->state(['ends' => 15])['state']);
        $this->assertSame('Owner has to renew by hand', $this->state(['ends' => 5])['note']);
        $this->assertSame('Renews automatically', $this->state(['ends' => 5, 'auto' => true])['note']);
        $this->assertSame('Automatic renewal failed', $this->state(['ends' => 5, 'auto' => true, 'failure' => 'Card declined'])['note']);
        $this->assertSame('Ending by the owner\'s choice', $this->state(['ends' => 5, 'byChoice' => true])['note']);
    }

    public function test_past_the_end_date_but_inside_the_grace_period_is_overdue(): void
    {
        $this->assertSame('overdue', $this->state(['ends' => -1])['state']);
        $this->assertSame('overdue', $this->state(['ends' => -6])['state']);
        $this->assertSame('Automatic renewal failed', $this->state(['ends' => -2, 'failure' => 'Card declined'])['note']);
    }

    public function test_past_the_grace_period_is_did_not_renew(): void
    {
        $this->assertSame(['state' => 'lapsed', 'note' => 'Did not renew'], $this->state(['ends' => -7]));
        $this->assertSame('lapsed', $this->state(['ends' => -40])['state']);
    }

    public function test_ending_by_choice_gets_no_grace(): void
    {
        $this->assertSame(['state' => 'lapsed', 'note' => 'Ended by the owner\'s choice'], $this->state(['ends' => -1, 'byChoice' => true]));
    }

    public function test_a_trial_that_ended_with_no_plan_bought_is_lapsed_and_says_so(): void
    {
        $this->assertSame(['state' => 'lapsed', 'note' => 'Trial ended, no plan bought'], $this->state(['trial' => true, 'ends' => -1]));
        // Ended early by status (a plan was started and abandoned): still not on trial.
        $this->assertSame('lapsed', $this->state(['trial' => true, 'status' => 'Expired', 'ends' => 5])['state']);
    }

    public function test_cancelled_and_expired_rows_are_lapsed(): void
    {
        $this->assertSame(['state' => 'lapsed', 'note' => 'Cancelled'], $this->state(['status' => 'Cancelled', 'ends' => 20]));
        $this->assertSame(['state' => 'lapsed', 'note' => 'Did not renew'], $this->state(['status' => 'Expired', 'ends' => 20]));
    }

    public function test_every_result_is_one_of_the_five_states(): void
    {
        foreach ([30, 14, 1, -1, -7, -60, null] as $ends) {
            foreach ([true, false] as $trial) {
                $this->assertContains($this->state(['ends' => $ends, 'trial' => $trial])['state'], SubscriberState::ALL);
            }
        }
    }
}

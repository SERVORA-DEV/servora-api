<?php

namespace Tests\Unit;

use App\Support\SubscriptionReminderSchedule as Schedule;
use Carbon\Carbon;
use Tests\TestCase;

// When an owner is reminded about their Servora plan — the rule itself, which
// has no database behind it.
//
//   php artisan test --filter=SubscriptionReminderScheduleTest
class SubscriptionReminderScheduleTest extends TestCase
{
    // A plan due Oct 30 at 2:23 PM. First reminder 7 days before, overdue
    // reminders every day, 7 days of grace: access ends Nov 6 at 2:23 PM.
    private const DUE = '2026-10-30 14:23:00';

    private function schedule(int $first = 7, int $every = 1, int $grace = 7, bool $early = true): Schedule
    {
        return new Schedule($first, $every, $grace, $early);
    }

    // The daily job runs at midnight.
    private function at(string $date, string $time = '00:00:00'): Carbon
    {
        return Carbon::parse("{$date} {$time}");
    }

    private function due(string $today, ?string $lastSent = null, array $with = [], ?Schedule $schedule = null): ?string
    {
        return ($schedule ?? $this->schedule())->due(
            Carbon::parse(self::DUE),
            $lastSent ? Carbon::parse($lastSent) : null,
            $this->at($today),
            $with['trial'] ?? false,
            $with['autoRenew'] ?? false,
            $with['ownerWantsEarly'] ?? true,
        );
    }

    public function test_nothing_before_the_first_reminder(): void
    {
        $this->assertNull($this->due('2026-10-22'));
    }

    public function test_the_three_moments_before_the_due_date(): void
    {
        $this->assertSame(Schedule::FIRST, $this->due('2026-10-23'));
        $this->assertSame(Schedule::DAY_BEFORE, $this->due('2026-10-29', '2026-10-23 00:00:05'));
        $this->assertSame(Schedule::DUE_TODAY, $this->due('2026-10-30', '2026-10-29 00:00:05'));
    }

    public function test_each_moment_is_sent_once(): void
    {
        // Already sent today: a second run the same day sends nothing.
        $this->assertNull($this->due('2026-10-23', '2026-10-23 00:00:05'));
        // Nothing on the quiet days in between.
        foreach (['2026-10-24', '2026-10-25', '2026-10-26', '2026-10-27', '2026-10-28'] as $day) {
            $this->assertNull($this->due($day, '2026-10-23 00:00:05'), $day);
        }
        $this->assertNull($this->due('2026-10-29', '2026-10-29 00:00:05'));
        $this->assertNull($this->due('2026-10-30', '2026-10-30 00:00:05'));
    }

    public function test_overdue_repeats_through_the_grace_period(): void
    {
        // Oct 31: lapsed yesterday afternoon, first overdue notice.
        $this->assertSame(Schedule::OVERDUE, $this->due('2026-10-31', '2026-10-30 00:00:05'));
        // Every day after that, once a day.
        $this->assertSame(Schedule::OVERDUE, $this->due('2026-11-01', '2026-10-31 00:00:05'));
        $this->assertNull($this->due('2026-11-01', '2026-11-01 00:00:05'));
        $this->assertSame(Schedule::OVERDUE, $this->due('2026-11-06', '2026-11-05 00:00:05'));
    }

    public function test_overdue_every_three_days(): void
    {
        $every3 = $this->schedule(every: 3);

        $this->assertSame(Schedule::OVERDUE, $this->due('2026-10-31', '2026-10-30 00:00:05', [], $every3));
        $this->assertNull($this->due('2026-11-01', '2026-10-31 00:00:05', [], $every3));
        $this->assertNull($this->due('2026-11-02', '2026-10-31 00:00:05', [], $every3));
        $this->assertSame(Schedule::OVERDUE, $this->due('2026-11-03', '2026-10-31 00:00:05', [], $every3));
    }

    public function test_access_paused_is_said_once_when_grace_is_over(): void
    {
        // Grace ends Nov 6 at 2:23 PM, so the midnight run on Nov 7 says it.
        $this->assertSame(Schedule::ENDED, $this->due('2026-11-07', '2026-11-06 00:00:05'));
        $this->assertNull($this->due('2026-11-08', '2026-11-07 00:00:05'));
        $this->assertNull($this->due('2026-12-01', '2026-11-07 00:00:05'));
    }

    public function test_an_owner_who_switched_early_reminders_off_still_hears_about_overdue(): void
    {
        $off = ['ownerWantsEarly' => false];

        $this->assertNull($this->due('2026-10-23', null, $off));
        $this->assertNull($this->due('2026-10-29', null, $off));
        $this->assertNull($this->due('2026-10-30', null, $off));
        $this->assertSame(Schedule::OVERDUE, $this->due('2026-10-31', null, $off));
        $this->assertSame(Schedule::ENDED, $this->due('2026-11-07', '2026-11-06 00:00:05', $off));
    }

    public function test_the_administrator_switching_early_reminders_off_does_the_same_for_everyone(): void
    {
        $off = $this->schedule(early: false);

        $this->assertNull($this->due('2026-10-23', null, [], $off));
        $this->assertNull($this->due('2026-10-30', null, [], $off));
        $this->assertSame(Schedule::OVERDUE, $this->due('2026-10-31', null, [], $off));
        $this->assertSame(Schedule::ENDED, $this->due('2026-11-07', null, [], $off));
    }

    public function test_auto_renew_gets_one_notice_before_the_charge(): void
    {
        $auto = ['autoRenew' => true];

        $this->assertSame(Schedule::FIRST, $this->due('2026-10-23', null, $auto));
        $this->assertNull($this->due('2026-10-29', '2026-10-23 00:00:05', $auto));
        $this->assertNull($this->due('2026-10-30', '2026-10-23 00:00:05', $auto));
        // The job missed the first day: the one notice still goes out, late.
        $this->assertSame(Schedule::FIRST, $this->due('2026-10-29', null, $auto));
        // The charge failed and the plan lapsed: overdue as for anyone.
        $this->assertSame(Schedule::OVERDUE, $this->due('2026-10-31', '2026-10-23 00:00:05', $auto));
    }

    public function test_a_free_trial_has_the_same_run_up_and_no_grace(): void
    {
        $trial = ['trial' => true];

        $this->assertSame(Schedule::FIRST, $this->due('2026-10-23', null, $trial));
        $this->assertSame(Schedule::DAY_BEFORE, $this->due('2026-10-29', '2026-10-23 00:00:05', $trial));
        $this->assertSame(Schedule::DUE_TODAY, $this->due('2026-10-30', '2026-10-29 00:00:05', $trial));
        // It ended yesterday afternoon: ended, not overdue.
        $this->assertSame(Schedule::ENDED, $this->due('2026-10-31', '2026-10-30 00:00:05', $trial));
        $this->assertNull($this->due('2026-11-01', '2026-10-31 00:00:05', $trial));
    }

    public function test_a_job_that_missed_days_sends_the_current_moment_only(): void
    {
        // Never reminded, first run on the due day: "due today", not the two before it.
        $this->assertSame(Schedule::DUE_TODAY, $this->due('2026-10-30'));
        // First run three days into the grace period: one overdue notice.
        $this->assertSame(Schedule::OVERDUE, $this->due('2026-11-02'));
    }

    public function test_a_first_reminder_of_one_day_is_just_the_day_before(): void
    {
        $oneDay = $this->schedule(first: 1);

        $this->assertNull($this->due('2026-10-28', null, [], $oneDay));
        $this->assertSame(Schedule::DAY_BEFORE, $this->due('2026-10-29', null, [], $oneDay));
        $this->assertSame(Schedule::DUE_TODAY, $this->due('2026-10-30', '2026-10-29 00:00:05', [], $oneDay));
    }

    public function test_no_grace_period_means_ended_straight_after_the_due_date(): void
    {
        $this->assertSame(Schedule::ENDED, $this->due('2026-10-31', '2026-10-30 00:00:05', [], $this->schedule(grace: 0)));
    }

    public function test_days_left_counts_whole_days_to_the_due_day(): void
    {
        $due = Carbon::parse(self::DUE);

        $this->assertSame(7, Schedule::daysLeft($due, $this->at('2026-10-23')));
        $this->assertSame(1, Schedule::daysLeft($due, $this->at('2026-10-29', '23:50:00')));
        $this->assertSame(0, Schedule::daysLeft($due, $this->at('2026-10-30', '09:00:00')));
        $this->assertSame(0, Schedule::daysLeft($due, $this->at('2026-11-02')));
    }

    public function test_the_run_up_in_words(): void
    {
        $this->assertSame('7 days before, the day before and on the due day', $this->schedule()->earlyInWords());
        $this->assertSame('the day before and on the due day', $this->schedule(first: 1)->earlyInWords());
    }
}

<?php

namespace Tests\Unit;

use App\Http\Requests\Business\BranchSettings\BranchBookingPolicyRequest;
use App\Models\SpaBusinessSetting;
use App\Service\Client\BookingPolicy;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

// How many times a client may move one booking in the app. No database.
class BookingPolicyRescheduleLimitTest extends TestCase
{
    private function policy(array $over = []): array
    {
        return $over + ['allow_reschedule' => true, 'allow_cancellation' => true, 'cancellation_window_hours' => 0, 'max_reschedules_per_booking' => 2];
    }

    private function startsAt(): Carbon
    {
        return BookingPolicy::now()->addDays(5);
    }

    public function test_a_booking_can_be_moved_until_the_limit_is_used(): void
    {
        $this->assertNull(BookingPolicy::changeProblem($this->policy(), $this->startsAt(), 'reschedule', 0));
        $this->assertNull(BookingPolicy::changeProblem($this->policy(), $this->startsAt(), 'reschedule', 1));
    }

    public function test_at_the_limit_it_is_refused_with_why(): void
    {
        $this->assertSame(
            "You've already moved this booking 2 times, the most this spa allows. Please contact the spa.",
            BookingPolicy::changeProblem($this->policy(), $this->startsAt(), 'reschedule', 2),
        );
        $this->assertStringContainsString('1 time,', BookingPolicy::changeProblem($this->policy(['max_reschedules_per_booking' => 1]), $this->startsAt(), 'reschedule', 1));
        $this->assertNotNull(BookingPolicy::changeProblem($this->policy(), $this->startsAt(), 'reschedule', 5));
    }

    public function test_a_limit_of_zero_means_no_rescheduling_in_the_app(): void
    {
        $this->assertSame(
            "This spa doesn't allow rescheduling from the app. Please contact the spa.",
            BookingPolicy::changeProblem($this->policy(['max_reschedules_per_booking' => 0]), $this->startsAt(), 'reschedule', 0),
        );
    }

    public function test_the_limit_never_touches_cancelling(): void
    {
        $this->assertNull(BookingPolicy::changeProblem($this->policy(['max_reschedules_per_booking' => 0]), $this->startsAt(), 'cancel', 9));
    }

    public function test_the_rescheduling_switch_still_comes_first(): void
    {
        $this->assertStringContainsString("doesn't allow rescheduling", BookingPolicy::changeProblem($this->policy(['allow_reschedule' => false]), $this->startsAt(), 'reschedule', 0));
    }

    public function test_it_is_a_business_default_a_branch_can_override(): void
    {
        $this->assertSame(2, SpaBusinessSetting::DEFAULTS['booking_defaults']['max_reschedules_per_booking']);
        $this->assertContains('max_reschedules_per_booking', BookingPolicy::KEYS);
        $this->assertContains('max_reschedules_per_booking', BranchBookingPolicyRequest::KEYS);
    }
}

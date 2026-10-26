<?php

namespace Tests\Unit;

use App\Service\Client\BookingPolicy;
use Tests\TestCase;

// The owner's "service time per booking" rule (Business Defaults, or the
// branch's own): how long one booking's services may add up to. The client
// app is refused past it; the front desk is only warned.
//
// No database.
//   php artisan test --filter=ServiceTimeLimitTest
class ServiceTimeLimitTest extends TestCase
{
    private function problem(int $limit, int $minutes): ?string
    {
        return BookingPolicy::serviceTimeProblem(['max_service_minutes_per_booking' => $limit], $minutes);
    }

    public function test_no_limit_means_any_length_is_fine(): void
    {
        $this->assertNull($this->problem(0, 600));
        $this->assertNull(BookingPolicy::serviceTimeProblem([], 600));
    }

    public function test_a_booking_at_or_under_the_limit_is_fine(): void
    {
        $this->assertNull($this->problem(120, 90));
        $this->assertNull($this->problem(120, 120));
    }

    public function test_a_booking_over_the_limit_is_explained_in_plain_time(): void
    {
        $message = $this->problem(120, 150);

        $this->assertNotNull($message);
        $this->assertStringContainsString('up to 2 hours per visit', $message);
        $this->assertStringContainsString('2 hours 30 minutes', $message);
    }

    public function test_without_overlap_a_package_is_held_to_the_limit_too(): void
    {
        $policy = ['max_service_minutes_per_booking' => 120];

        $message = BookingPolicy::serviceTimeProblem($policy, 240, 240, true);

        $this->assertNotNull($message);
        $this->assertStringContainsString('this package takes 4 hours', $message);
        $this->assertNotNull(BookingPolicy::serviceTimeProblem($policy, 180, 180));
        $this->assertNull(BookingPolicy::serviceTimeProblem($policy, 120, 120, true));
    }

    // With "booking overlap" on: the limit only stops more services being added.
    private function services(int $limit, int $total, int $longest, bool $package = false): ?string
    {
        return BookingPolicy::serviceTimeProblem(['max_service_minutes_per_booking' => $limit, 'allow_booking_overlap' => true], $total, $longest, $package);
    }

    public function test_a_package_is_never_over_the_limit(): void
    {
        $this->assertNull($this->services(120, 240, 240, package: true));
    }

    public function test_one_long_service_on_its_own_is_allowed(): void
    {
        $this->assertNull($this->services(120, 180, 180));
    }

    public function test_a_service_can_be_added_while_the_rest_is_under_the_limit(): void
    {
        // 60 chosen, then a 120 added: nothing was at the limit when it went on.
        $this->assertNull($this->services(120, 180, 120));
        $this->assertNull($this->services(120, 150, 90));
    }

    public function test_nothing_more_is_added_once_the_services_reach_the_limit(): void
    {
        // 120 already chosen: a 30 on top of it is one too many.
        $this->assertNotNull($this->services(120, 150, 30));
        $this->assertNotNull($this->services(120, 240, 120));
    }

    public function test_minutes_read_as_hours_and_minutes(): void
    {
        $this->assertSame('2 hours', BookingPolicy::formatMinutes(120));
        $this->assertSame('1 hour 30 minutes', BookingPolicy::formatMinutes(90));
        $this->assertSame('45 minutes', BookingPolicy::formatMinutes(45));
        $this->assertSame('1 hour 1 minute', BookingPolicy::formatMinutes(61));
    }
}

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

    public function test_minutes_read_as_hours_and_minutes(): void
    {
        $this->assertSame('2 hours', BookingPolicy::formatMinutes(120));
        $this->assertSame('1 hour 30 minutes', BookingPolicy::formatMinutes(90));
        $this->assertSame('45 minutes', BookingPolicy::formatMinutes(45));
        $this->assertSame('1 hour 1 minute', BookingPolicy::formatMinutes(61));
    }
}

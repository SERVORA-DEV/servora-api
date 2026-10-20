<?php

namespace Tests\Unit;

use App\Service\Client\BookingPolicy;
use PHPUnit\Framework\TestCase;

// What a client is told about their missed bookings. No database.
class MissedBookingsNoticeTest extends TestCase
{
    private function policy(string $kind = 'suspend', int $after = 3): array
    {
        return ['no_show_policy' => $kind, 'no_show_suspend_after' => $after];
    }

    public function test_nothing_is_said_unless_the_policy_pauses_booking(): void
    {
        foreach (['none', 'warn'] as $kind) {
            $notice = BookingPolicy::missedBookingsNotice($this->policy($kind), 5, 'Serenity Spa');

            $this->assertFalse($notice['blocked']);
            $this->assertNull($notice['message']);
            $this->assertNull($notice['limit']);
            $this->assertSame(5, $notice['missed']);
        }
    }

    public function test_a_client_who_has_missed_nothing_is_not_flagged(): void
    {
        $notice = BookingPolicy::missedBookingsNotice($this->policy(), 0, 'Serenity Spa');

        $this->assertFalse($notice['blocked']);
        $this->assertNull($notice['message']);
        $this->assertSame(3, $notice['limit']);
    }

    public function test_a_miss_below_the_limit_is_a_warning_that_attending_clears_it(): void
    {
        $notice = BookingPolicy::missedBookingsNotice($this->policy(), 1, 'Serenity Spa');

        $this->assertFalse($notice['blocked']);
        $this->assertStringContainsString('You missed 1 booking.', $notice['message']);
        $this->assertStringContainsString('Missing 3', $notice['message']);
        $this->assertStringContainsString('next visit starts the count again', $notice['message']);
    }

    public function test_at_the_limit_booking_is_paused_and_names_the_spa(): void
    {
        $notice = BookingPolicy::missedBookingsNotice($this->policy('suspend', 2), 2, 'Serenity Spa');

        $this->assertTrue($notice['blocked']);
        $this->assertStringContainsString('paused', $notice['message']);
        $this->assertStringContainsString('missed 2 bookings', $notice['message']);
        $this->assertStringContainsString('Serenity Spa', $notice['message']);
    }
}

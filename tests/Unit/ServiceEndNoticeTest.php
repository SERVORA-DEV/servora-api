<?php

namespace Tests\Unit;

use App\Support\ServiceEndNotice;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

// The front desk's "time is up" notice: when it is due, and what it says.
class ServiceEndNoticeTest extends TestCase
{
    public function test_a_service_is_due_once_its_length_has_passed(): void
    {
        $started = Carbon::parse('2026-10-09 19:40:00');

        $this->assertFalse(ServiceEndNotice::isDue($started, 60, Carbon::parse('2026-10-09 20:39:59')));
        $this->assertTrue(ServiceEndNotice::isDue($started, 60, Carbon::parse('2026-10-09 20:40:00')));
        $this->assertTrue(ServiceEndNotice::isDue($started, 60, Carbon::parse('2026-10-09 21:15:00')));
        $this->assertSame('2026-10-09 20:40:00', ServiceEndNotice::dueAt($started, 60)->toDateTimeString());
    }

    public function test_it_does_not_change_the_start_time_it_was_given(): void
    {
        $started = Carbon::parse('2026-10-09 19:40:00');
        ServiceEndNotice::dueAt($started, 90);

        $this->assertSame('2026-10-09 19:40:00', $started->toDateTimeString());
    }

    public function test_a_service_with_no_length_is_never_due(): void
    {
        $started = Carbon::parse('2026-10-09 19:40:00');

        $this->assertNull(ServiceEndNotice::dueAt($started, null));
        $this->assertNull(ServiceEndNotice::dueAt($started, 0));
        $this->assertFalse(ServiceEndNotice::isDue($started, 0, Carbon::parse('2026-10-10 09:00:00')));
    }

    public function test_the_title_names_the_service(): void
    {
        $this->assertSame('Time is up: Hilot', ServiceEndNotice::title('Hilot'));
        $this->assertStringStartsWith(ServiceEndNotice::TITLE_PREFIX, ServiceEndNotice::title('Classic Facial'));
    }

    public function test_the_message_says_who_what_where_and_when(): void
    {
        $message = ServiceEndNotice::message('Hilot', 'Nicole De La Gente', Carbon::parse('2026-10-09 21:05:00'), ['Liza Fernandez'], 'Massage Room 1');

        $this->assertSame(
            "Nicole De La Gente's Hilot in Massage Room 1 with Liza Fernandez reached its time at 9:05 PM. Mark it complete once it is done so the room is free.",
            $message,
        );
    }

    public function test_the_message_reads_properly_without_a_room_or_with_several_therapists(): void
    {
        $due = Carbon::parse('2026-10-09 21:05:00');

        $this->assertSame(
            "Nika Espantaleon's Classic Facial with Jomar Castillo reached its time at 9:05 PM. Mark it complete once it is done.",
            ServiceEndNotice::message('Classic Facial', 'Nika Espantaleon', $due, ['Jomar Castillo']),
        );
        $this->assertStringContainsString(
            'with Aileen Pascual, Grace Villanueva and Liza Fernandez reached',
            ServiceEndNotice::message('Couples Massage', 'A client', $due, ['Aileen Pascual', 'Grace Villanueva', 'Liza Fernandez'], 'Couples Suite'),
        );
        $this->assertSame(
            "A client's Hilot reached its time at 9:05 PM. Mark it complete once it is done.",
            ServiceEndNotice::message('Hilot', 'A client', $due),
        );
    }
}

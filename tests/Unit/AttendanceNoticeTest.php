<?php

namespace Tests\Unit;

use App\Support\AttendanceNotice;
use Tests\TestCase;

// What managers read when someone's attendance is marked, corrected or cleared.
//
// No database.
//   php artisan test --filter=AttendanceNoticeTest
class AttendanceNoticeTest extends TestCase
{
    public function test_a_new_record_says_who_marked_what(): void
    {
        [$title, $message] = AttendanceNotice::describe('Rosa', 'Mia Athena', '2026-10-29', null, ['status' => 'Late', 'check_in_at' => '2026-10-29 09:20:00']);

        $this->assertSame('Attendance marked', $title);
        $this->assertSame('Rosa marked Mia Athena late for Oct 29.', $message);
    }

    public function test_a_correction_lists_each_change_in_words(): void
    {
        [$title, $message] = AttendanceNotice::describe(
            'Rosa', 'Mia Athena', '2026-10-29',
            ['status' => 'Late', 'check_in_at' => '2026-10-29 09:20:00', 'check_out_at' => null, 'remarks' => null],
            ['status' => 'Present', 'check_in_at' => '2026-10-29 09:00:00', 'check_out_at' => null, 'remarks' => null],
        );

        $this->assertSame('Attendance changed', $title);
        $this->assertSame("Rosa changed Mia Athena's attendance for Oct 29: status was Late, now Present; time in was 9:20 AM, now 9:00 AM.", $message);
        $this->assertStringNotContainsString('→', $message);
    }

    public function test_an_empty_value_reads_not_set(): void
    {
        [, $message] = AttendanceNotice::describe('Rosa', 'Mia', '2026-10-29', ['status' => 'Present', 'check_out_at' => null], ['status' => 'Present', 'check_out_at' => '2026-10-29 18:00:00']);

        $this->assertStringContainsString('time out was not set, now 6:00 PM', $message);
    }

    public function test_notes_are_not_quoted(): void
    {
        [, $message] = AttendanceNotice::describe('Rosa', 'Mia', '2026-10-29', ['remarks' => 'a'], ['remarks' => 'b']);

        $this->assertStringContainsString('notes were updated', $message);
        $this->assertStringNotContainsString('was a', $message);
    }

    public function test_clearing_a_record_says_so(): void
    {
        [$title, $message] = AttendanceNotice::describe('Rosa', 'Mia Athena', '2026-10-29', ['status' => 'Late'], null);

        $this->assertSame('Attendance cleared', $title);
        $this->assertSame("Rosa cleared Mia Athena's attendance for Oct 29.", $message);
    }

    public function test_a_save_that_changed_nothing_says_nothing(): void
    {
        $same = ['status' => 'Present', 'check_in_at' => '2026-10-29 09:00:00', 'check_out_at' => null, 'remarks' => null];

        $this->assertNull(AttendanceNotice::describe('Rosa', 'Mia', '2026-10-29', $same, $same));
    }
}

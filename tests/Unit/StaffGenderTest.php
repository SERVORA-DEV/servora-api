<?php

namespace Tests\Unit;

use App\Support\StaffGender;
use PHPUnit\Framework\TestCase;

// The staff gender a booking asked for: a filter on the list, kept on the
// booking as advice for whoever assigns.
class StaffGenderTest extends TestCase
{
    public function test_a_preference_is_male_or_female(): void
    {
        $this->assertSame('Male', StaffGender::clean('male'));
        $this->assertSame('Female', StaffGender::clean(' Female '));
    }

    public function test_anything_else_is_no_preference(): void
    {
        $this->assertNull(StaffGender::clean('Any'));
        $this->assertNull(StaffGender::clean('Other'));
        $this->assertNull(StaffGender::clean(''));
        $this->assertNull(StaffGender::clean(null));
        $this->assertNull(StaffGender::clean(['Male']));
    }

    public function test_with_no_preference_everyone_suits(): void
    {
        $this->assertTrue(StaffGender::matches('Male', null));
        $this->assertTrue(StaffGender::matches('Female', null));
        $this->assertTrue(StaffGender::matches('Other', null));
        $this->assertTrue(StaffGender::matches(null, null));
        $this->assertTrue(StaffGender::matches(null, 'Any'));
    }

    public function test_a_preference_keeps_only_that_gender(): void
    {
        $this->assertTrue(StaffGender::matches('Female', 'Female'));
        $this->assertFalse(StaffGender::matches('Male', 'Female'));
        $this->assertTrue(StaffGender::matches('male', 'Male'));
        $this->assertFalse(StaffGender::matches('Other', 'Male'));
    }

    public function test_staff_with_no_gender_on_record_match_no_preference(): void
    {
        $this->assertFalse(StaffGender::matches(null, 'Female'));
        $this->assertFalse(StaffGender::matches('', 'Male'));
    }

    public function test_the_note_on_a_booking(): void
    {
        $this->assertSame('Prefers female staff', StaffGender::label('Female'));
        $this->assertSame('Prefers male staff', StaffGender::label('male'));
        $this->assertNull(StaffGender::label(null));
        $this->assertNull(StaffGender::label('Any'));
    }
}

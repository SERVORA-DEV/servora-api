<?php

namespace Tests\Unit;

use App\Support\StaffSpecialty;
use PHPUnit\Framework\TestCase;

// What a member of staff does: a fixed list, several per person, the first
// one shown beside their name.
class StaffSpecialtyTest extends TestCase
{
    public function test_the_list_is_the_fifteen_agreed(): void
    {
        $this->assertCount(6, StaffSpecialty::ALL);
        $this->assertSame('Therapist', StaffSpecialty::ALL[0]);
        $this->assertContains('Skincare Specialist', StaffSpecialty::ALL);
        $this->assertContains('Body Treatment Specialist', StaffSpecialty::ALL);
        $this->assertSame(StaffSpecialty::ALL, array_values(array_unique(StaffSpecialty::ALL)));
    }

    public function test_only_real_specialties_are_kept_once_each_in_the_order_given(): void
    {
        $this->assertSame(
            ['Skincare Specialist', 'Waxing Specialist'],
            StaffSpecialty::clean(['Skincare Specialist', 'Plumber', 'Waxing Specialist', 'Skincare Specialist']),
        );
        // The first given stays first, whatever the list's own order is.
        $this->assertSame(['Massage Therapist', 'Therapist'], StaffSpecialty::clean(['Massage Therapist', 'Therapist']));
    }

    public function test_spelling_is_forgiven_on_case_and_spaces(): void
    {
        $this->assertSame(['Spa Attendant'], StaffSpecialty::clean(['  spa   attendant ']));
        $this->assertSame(['Massage Therapist'], StaffSpecialty::clean(['MASSAGE THERAPIST']));
    }

    public function test_anything_that_is_not_a_list_is_nothing(): void
    {
        $this->assertSame([], StaffSpecialty::clean(null));
        $this->assertSame([], StaffSpecialty::clean('Skincare Specialist'));
        $this->assertSame([], StaffSpecialty::clean([null, 5, ['Skincare Specialist']]));
    }

    public function test_the_first_is_the_main_one(): void
    {
        $this->assertSame('Skincare Specialist', StaffSpecialty::main(['Skincare Specialist', 'Waxing Specialist']));
        $this->assertNull(StaffSpecialty::main([]));
        $this->assertNull(StaffSpecialty::main(['Plumber']));
    }

    public function test_the_label_counts_the_others(): void
    {
        $this->assertSame('', StaffSpecialty::label([]));
        $this->assertSame('Spa Attendant', StaffSpecialty::label(['Spa Attendant']));
        $this->assertSame('Spa Attendant +2', StaffSpecialty::label(['Spa Attendant', 'Skincare Specialist', 'Waxing Specialist']));
    }

    public function test_staff_always_have_one_and_start_as_therapist(): void
    {
        $this->assertSame(['Therapist'], StaffSpecialty::forRole('therapist', null));
        $this->assertSame(['Therapist'], StaffSpecialty::forRole('therapist', []));
        $this->assertSame(['Waxing Specialist'], StaffSpecialty::forRole('therapist', ['Waxing Specialist']));
        // Nothing given: what they had is kept.
        $this->assertSame(['Skincare Specialist'], StaffSpecialty::forRole('therapist', null, ['Skincare Specialist']));
        $this->assertSame(['Spa Attendant'], StaffSpecialty::forRole('therapist', ['Spa Attendant'], ['Skincare Specialist']));
    }

    public function test_managers_and_the_front_desk_have_none(): void
    {
        $this->assertSame([], StaffSpecialty::forRole('manager', ['Waxing Specialist']));
        $this->assertSame([], StaffSpecialty::forRole('frontdesk', ['Waxing Specialist'], ['Skincare Specialist']));
        $this->assertSame([], StaffSpecialty::forRole(null, ['Waxing Specialist']));
    }
}

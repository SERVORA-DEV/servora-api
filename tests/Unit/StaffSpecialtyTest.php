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
        $this->assertCount(15, StaffSpecialty::ALL);
        $this->assertSame('Therapist', StaffSpecialty::ALL[0]);
        $this->assertContains('Nail Technician', StaffSpecialty::ALL);
        $this->assertContains('Body Treatment Specialist', StaffSpecialty::ALL);
        $this->assertSame(StaffSpecialty::ALL, array_values(array_unique(StaffSpecialty::ALL)));
    }

    public function test_only_real_specialties_are_kept_once_each_in_the_order_given(): void
    {
        $this->assertSame(
            ['Nail Technician', 'Lash Technician'],
            StaffSpecialty::clean(['Nail Technician', 'Plumber', 'Lash Technician', 'Nail Technician']),
        );
        // The first given stays first, whatever the list's own order is.
        $this->assertSame(['Massage Therapist', 'Therapist'], StaffSpecialty::clean(['Massage Therapist', 'Therapist']));
    }

    public function test_spelling_is_forgiven_on_case_and_spaces(): void
    {
        $this->assertSame(['Hair Stylist'], StaffSpecialty::clean(['  hair   stylist ']));
        $this->assertSame(['Makeup Artist'], StaffSpecialty::clean(['MAKEUP ARTIST']));
    }

    public function test_anything_that_is_not_a_list_is_nothing(): void
    {
        $this->assertSame([], StaffSpecialty::clean(null));
        $this->assertSame([], StaffSpecialty::clean('Nail Technician'));
        $this->assertSame([], StaffSpecialty::clean([null, 5, ['Nail Technician']]));
    }

    public function test_the_first_is_the_main_one(): void
    {
        $this->assertSame('Nail Technician', StaffSpecialty::main(['Nail Technician', 'Nail Artist']));
        $this->assertNull(StaffSpecialty::main([]));
        $this->assertNull(StaffSpecialty::main(['Plumber']));
    }

    public function test_the_label_counts_the_others(): void
    {
        $this->assertSame('', StaffSpecialty::label([]));
        $this->assertSame('Hair Stylist', StaffSpecialty::label(['Hair Stylist']));
        $this->assertSame('Hair Stylist +2', StaffSpecialty::label(['Hair Stylist', 'Hair Colorist', 'Hair Treatment Specialist']));
    }

    public function test_staff_always_have_one_and_start_as_therapist(): void
    {
        $this->assertSame(['Therapist'], StaffSpecialty::forRole('therapist', null));
        $this->assertSame(['Therapist'], StaffSpecialty::forRole('therapist', []));
        $this->assertSame(['Nail Artist'], StaffSpecialty::forRole('therapist', ['Nail Artist']));
        // Nothing given: what they had is kept.
        $this->assertSame(['Beautician'], StaffSpecialty::forRole('therapist', null, ['Beautician']));
        $this->assertSame(['Spa Attendant'], StaffSpecialty::forRole('therapist', ['Spa Attendant'], ['Beautician']));
    }

    public function test_managers_and_the_front_desk_have_none(): void
    {
        $this->assertSame([], StaffSpecialty::forRole('manager', ['Nail Artist']));
        $this->assertSame([], StaffSpecialty::forRole('frontdesk', ['Nail Artist'], ['Beautician']));
        $this->assertSame([], StaffSpecialty::forRole(null, ['Nail Artist']));
    }
}

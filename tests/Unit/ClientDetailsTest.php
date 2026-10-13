<?php

namespace Tests\Unit;

use App\Support\ClientProfile;
use PHPUnit\Framework\TestCase;

// What a spa records about a client: gender, an age kept as a birth year,
// and what a home service needs before it can be booked.
class ClientDetailsTest extends TestCase
{
    public function test_gender_is_male_or_female_whatever_the_case(): void
    {
        $this->assertSame('Male', ClientProfile::gender('Male'));
        $this->assertSame('Female', ClientProfile::gender(' female '));
        $this->assertSame('Male', ClientProfile::gender('MALE'));
    }

    public function test_anything_else_is_not_recorded(): void
    {
        $this->assertNull(ClientProfile::gender('Prefer not to say'));
        $this->assertNull(ClientProfile::gender('Other'));
        $this->assertNull(ClientProfile::gender(''));
        $this->assertNull(ClientProfile::gender(null));
        $this->assertNull(ClientProfile::gender(1));
    }

    public function test_an_age_is_kept_as_the_year_of_birth(): void
    {
        $this->assertSame(1992, ClientProfile::birthYear(34, 2026));
        $this->assertSame(1992, ClientProfile::birthYear('34', 2026));
        $this->assertSame(2026, ClientProfile::birthYear(0, 2026));
    }

    public function test_no_age_or_a_silly_one_is_nothing(): void
    {
        $this->assertNull(ClientProfile::birthYear(null, 2026));
        $this->assertNull(ClientProfile::birthYear('', 2026));
        $this->assertNull(ClientProfile::birthYear('old', 2026));
        $this->assertNull(ClientProfile::birthYear(-1, 2026));
        $this->assertNull(ClientProfile::birthYear(121, 2026));
    }

    public function test_the_age_moves_on_with_the_years(): void
    {
        $born = ClientProfile::birthYear(34, 2026);
        $this->assertSame(34, ClientProfile::age($born, 2026));
        $this->assertSame(35, ClientProfile::age($born, 2027));
        $this->assertNull(ClientProfile::age(null, 2026));
        // A year that can't be right shows no age rather than a wrong one.
        $this->assertNull(ClientProfile::age(2030, 2026));
        $this->assertNull(ClientProfile::age(1800, 2026));
    }

    public function test_a_home_service_needs_a_phone_number_and_the_gender(): void
    {
        $this->assertSame([], ClientProfile::missingForHome(['phone_number' => '0917 123 4567', 'gender' => 'Female']));
        $this->assertSame(['phone number'], ClientProfile::missingForHome(['phone_number' => '  ', 'gender' => 'Male']));
        $this->assertSame(['gender'], ClientProfile::missingForHome(['phone_number' => '0917', 'gender' => null]));
        $this->assertSame(['phone number', 'gender'], ClientProfile::missingForHome([]));
        // An account that said "Prefer not to say" hasn't told the spa.
        $this->assertSame(['gender'], ClientProfile::missingForHome(['phone_number' => '0917', 'gender' => 'Prefer not to say']));
    }

    public function test_what_is_missing_is_said_plainly(): void
    {
        $this->assertSame('Add the client\'s phone number and gender for a home service.', ClientProfile::homeMessage(['phone number', 'gender']));
        $this->assertSame('Add the client\'s gender for a home service.', ClientProfile::homeMessage(['gender']));
    }
}

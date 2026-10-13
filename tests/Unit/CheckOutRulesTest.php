<?php

namespace Tests\Unit;

use App\Support\CheckOutRules;
use PHPUnit\Framework\TestCase;

// A therapist performing a service can't be checked out.
class CheckOutRulesTest extends TestCase
{
    public function test_someone_not_in_a_service_can_be_checked_out(): void
    {
        $this->assertNull(CheckOutRules::problem('Lara Mendoza', null));
    }

    public function test_a_therapist_in_a_service_cannot_and_the_reason_names_it(): void
    {
        $this->assertSame(
            'Lara Mendoza is performing Swedish Massage for Maria Santos right now. Complete that service first.',
            CheckOutRules::problem('Lara Mendoza', ['service' => 'Swedish Massage', 'client' => 'Maria Santos']),
        );
    }

    public function test_the_reason_still_reads_well_with_details_missing(): void
    {
        $this->assertSame(
            'Lara Mendoza is performing Swedish Massage right now. Complete that service first.',
            CheckOutRules::problem('Lara Mendoza', ['service' => 'Swedish Massage', 'client' => ' ']),
        );
        $this->assertSame(
            'Lara Mendoza is performing a service right now. Complete that service first.',
            CheckOutRules::problem('Lara Mendoza', ['service' => null, 'client' => null]),
        );
    }
}

<?php

namespace Tests\Unit;

use App\Support\ServiceKind;
use PHPUnit\Framework\TestCase;

// Time-based, procedure-based, facility-based: what each needs and allows.
class ServiceKindTest extends TestCase
{
    public function test_anything_unknown_is_time_based(): void
    {
        $this->assertSame('time', ServiceKind::of(null));
        $this->assertSame('time', ServiceKind::of(''));
        $this->assertSame('time', ServiceKind::of('package'));
        $this->assertSame('procedure', ServiceKind::of('procedure'));
        $this->assertSame('facility', ServiceKind::of('facility'));
    }

    public function test_only_a_facility_based_service_needs_no_therapist(): void
    {
        $this->assertTrue(ServiceKind::needsTherapist('time'));
        $this->assertTrue(ServiceKind::needsTherapist('procedure'));
        $this->assertFalse(ServiceKind::needsTherapist('facility'));
    }

    // A facility-based service always needs one; anything else only when the
    // owner ticked "Facility required" — a haircut or nails usually doesn't.
    public function test_a_facility_based_service_always_needs_a_facility_and_others_only_when_ticked(): void
    {
        $this->assertTrue(ServiceKind::needsFacility('facility'));
        $this->assertTrue(ServiceKind::needsFacility('facility', false));

        foreach (['time', 'procedure', null, 'package'] as $kind) {
            $this->assertFalse(ServiceKind::needsFacility($kind), "{$kind} defaults to optional");
            $this->assertFalse(ServiceKind::needsFacility($kind, false));
            $this->assertTrue(ServiceKind::needsFacility($kind, true));
        }
    }

    public function test_a_procedure_is_an_estimate_with_one_price(): void
    {
        $this->assertFalse(ServiceKind::isTimed('procedure'));
        $this->assertFalse(ServiceKind::hasLengths('procedure'));

        foreach (['time', 'facility', null] as $kind) {
            $this->assertTrue(ServiceKind::isTimed($kind));
            $this->assertTrue(ServiceKind::hasLengths($kind));
        }
    }

    public function test_one_therapist_may_do_two_procedures_at_once_but_nothing_measured_in_time(): void
    {
        $this->assertTrue(ServiceKind::canShareTherapist('procedure', 'procedure'));
        $this->assertFalse(ServiceKind::canShareTherapist('procedure', 'time'));
        $this->assertFalse(ServiceKind::canShareTherapist('time', 'procedure'));
        $this->assertFalse(ServiceKind::canShareTherapist('time', 'time'));
        $this->assertFalse(ServiceKind::canShareTherapist(null, 'procedure'));
    }

    public function test_each_kind_has_a_name(): void
    {
        $this->assertSame('Time-based', ServiceKind::label(null));
        $this->assertSame('Procedure-based', ServiceKind::label('procedure'));
        $this->assertSame('Facility-based', ServiceKind::label('facility'));
    }
}

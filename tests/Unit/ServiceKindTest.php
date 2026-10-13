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

    // An open bed, a chair, the sauna itself: a room is anyone's to pick and
    // nobody's to need.
    public function test_no_kind_needs_a_room(): void
    {
        $this->assertFalse(method_exists(ServiceKind::class, 'needsRoom'));
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

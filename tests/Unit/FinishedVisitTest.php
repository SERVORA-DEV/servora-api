<?php

namespace Tests\Unit;

use App\Support\FinishedVisit;
use App\Support\ServiceVoidRules;
use PHPUnit\Framework\TestCase;

// A finished, paid visit can't be voided or refunded.
class FinishedVisitTest extends TestCase
{
    public function test_a_finished_visit_is_locked(): void
    {
        $this->assertTrue(FinishedVisit::isLocked('completed'));
        $this->assertSame(FinishedVisit::LOCKED, FinishedVisit::problem('completed'));
    }

    public function test_a_visit_still_going_is_not(): void
    {
        foreach (['pending', 'confirmed', 'checked_in', 'in_service'] as $status) {
            $this->assertNull(FinishedVisit::problem($status), $status);
        }
    }

    public function test_a_cancelled_booking_can_still_be_refunded(): void
    {
        $this->assertFalse(FinishedVisit::isLocked('cancelled'));
        $this->assertFalse(FinishedVisit::isLocked('no_show'));
    }

    public function test_a_bill_with_no_visit_is_not_locked(): void
    {
        $this->assertFalse(FinishedVisit::isLocked(null));
    }

    public function test_a_service_on_a_finished_visit_cannot_be_voided(): void
    {
        $bill = ['status' => 'Paid', 'kept' => 800.0, 'discount' => 0.0];

        $this->assertSame(FinishedVisit::LOCKED, ServiceVoidRules::problem('Completed', 'completed', $bill, false));
        $this->assertSame(FinishedVisit::LOCKED, ServiceVoidRules::problem('Completed', 'completed', null, false));
    }
}

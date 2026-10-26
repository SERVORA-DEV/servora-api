<?php

namespace Tests\Unit;

use App\Support\BillAdjustment;
use PHPUnit\Framework\TestCase;

// Changing a paid bill: who has to approve it, and what a voucher hands back.
class BillAdjustmentTest extends TestCase
{
    // ── Who approves ────────────────────────────────────────────────────

    public function test_the_owner_never_needs_approval(): void
    {
        $this->assertSame(BillAdjustment::NOW, BillAdjustment::route('business_owner', false));
        $this->assertSame(BillAdjustment::NOW, BillAdjustment::route('business_owner', true));
    }

    public function test_with_the_permission_it_applies_at_once(): void
    {
        $this->assertSame(BillAdjustment::NOW, BillAdjustment::route('manager', true));
        $this->assertSame(BillAdjustment::NOW, BillAdjustment::route('front_officer', true));
    }

    public function test_a_front_desk_request_goes_to_a_manager_or_the_owner(): void
    {
        $this->assertSame(BillAdjustment::MANAGER, BillAdjustment::route('front_officer', false));
    }

    public function test_a_manager_request_goes_to_the_owner(): void
    {
        $this->assertSame(BillAdjustment::OWNER, BillAdjustment::route('manager', false));
    }

    public function test_the_owner_answers_every_request(): void
    {
        $this->assertTrue(BillAdjustment::mayDecide('business_owner', false, BillAdjustment::MANAGER, false, false));
        $this->assertTrue(BillAdjustment::mayDecide('business_owner', false, BillAdjustment::OWNER, false, false));
    }

    public function test_a_manager_answers_the_front_desk_at_their_branch(): void
    {
        $this->assertTrue(BillAdjustment::mayDecide('manager', true, BillAdjustment::MANAGER, true, false));
    }

    public function test_a_manager_never_answers_a_managers_request(): void
    {
        $this->assertFalse(BillAdjustment::mayDecide('manager', true, BillAdjustment::OWNER, true, false));
    }

    public function test_a_manager_who_could_not_make_it_cannot_approve_it(): void
    {
        $this->assertFalse(BillAdjustment::mayDecide('manager', false, BillAdjustment::MANAGER, true, false));
    }

    public function test_a_manager_of_another_branch_cannot_approve_it(): void
    {
        $this->assertFalse(BillAdjustment::mayDecide('manager', true, BillAdjustment::MANAGER, false, false));
    }

    public function test_the_front_desk_never_approves(): void
    {
        $this->assertFalse(BillAdjustment::mayDecide('front_officer', true, BillAdjustment::MANAGER, true, false));
    }

    public function test_nobody_answers_their_own_request(): void
    {
        $this->assertFalse(BillAdjustment::mayDecide('manager', true, BillAdjustment::MANAGER, true, true));
        $this->assertFalse(BillAdjustment::mayDecide('business_owner', true, BillAdjustment::MANAGER, true, true));
    }

    // ── Which bills ─────────────────────────────────────────────────────

    public function test_only_a_paid_visit_bill_can_be_adjusted(): void
    {
        $this->assertNull(BillAdjustment::billProblem('Paid', 'Appointment'));
        $this->assertNotNull(BillAdjustment::billProblem('Pending', 'Appointment'));
        $this->assertNotNull(BillAdjustment::billProblem('Partially Paid', 'Appointment'));
        $this->assertNotNull(BillAdjustment::billProblem('Refunded', 'Appointment'));
        $this->assertNotNull(BillAdjustment::billProblem('Paid', 'Subscription'));
    }

    public function test_a_reason_is_required(): void
    {
        $this->assertNotNull(BillAdjustment::reasonProblem(null));
        $this->assertNotNull(BillAdjustment::reasonProblem('  ok '));
        $this->assertNull(BillAdjustment::reasonProblem('Client complained'));
    }

    // ── Refunds ─────────────────────────────────────────────────────────

    public function test_a_refund_is_at_most_what_was_kept(): void
    {
        $this->assertNull(BillAdjustment::refundProblem(500, 1000));
        $this->assertNull(BillAdjustment::refundProblem(1000, 1000));
        $this->assertNotNull(BillAdjustment::refundProblem(1000.5, 1000));
        $this->assertNotNull(BillAdjustment::refundProblem(0, 1000));
        $this->assertNotNull(BillAdjustment::refundProblem(10, 0));
    }

    // ── Vouchers ────────────────────────────────────────────────────────

    public function test_a_voucher_hands_back_its_value(): void
    {
        $this->assertSame(['discount' => 200.0, 'amount' => 800.0, 'give_back' => 200.0], BillAdjustment::voucher(1000, 0, 1000, 'amount', 200));
    }

    public function test_a_percent_voucher_is_taken_off_the_subtotal(): void
    {
        $this->assertSame(['discount' => 100.0, 'amount' => 900.0, 'give_back' => 100.0], BillAdjustment::voucher(1000, 0, 1000, 'percent', 10));
    }

    public function test_a_voucher_replaces_a_smaller_discount_and_hands_back_the_difference(): void
    {
        // ₱1,000 bill with ₱100 off, paid ₱900; a ₱300 voucher makes it ₱700.
        $this->assertSame(['discount' => 300.0, 'amount' => 700.0, 'give_back' => 200.0], BillAdjustment::voucher(1000, 100, 900, 'amount', 300));
    }

    public function test_a_voucher_worth_no_more_than_the_discount_is_refused(): void
    {
        $this->assertIsString(BillAdjustment::voucher(1000, 300, 700, 'amount', 300));
        $this->assertIsString(BillAdjustment::voucher(1000, 300, 700, 'amount', 200));
    }

    public function test_money_already_refunded_is_not_handed_back_twice(): void
    {
        // Paid ₱1,000, ₱150 already refunded; a ₱200 voucher hands back ₱50 more.
        $this->assertSame(['discount' => 200.0, 'amount' => 800.0, 'give_back' => 50.0], BillAdjustment::voucher(1000, 0, 850, 'amount', 200));
        // ₱300 already refunded: nothing left to give for it.
        $this->assertIsString(BillAdjustment::voucher(1000, 0, 700, 'amount', 200));
    }

    // ── Before payment ──────────────────────────────────────────────────

    public function test_money_comes_off_only_a_bill_still_open(): void
    {
        $this->assertNull(BillAdjustment::openBillProblem('Pending', 'Appointment'));
        $this->assertNull(BillAdjustment::openBillProblem('Partially Paid', 'Appointment'));
        $this->assertNotNull(BillAdjustment::openBillProblem('Paid', 'Appointment'));
        $this->assertNotNull(BillAdjustment::openBillProblem('Cancelled', 'Appointment'));
        $this->assertNotNull(BillAdjustment::openBillProblem('Pending', 'Subscription'));
    }

    public function test_never_more_than_is_still_owed(): void
    {
        $this->assertNull(BillAdjustment::creditProblem(300, 1100));
        $this->assertNull(BillAdjustment::creditProblem(1100, 1100));
        // ₱600 paid already on a ₱1,100 bill: at most ₱500 can come off.
        $this->assertNotNull(BillAdjustment::creditProblem(600, 500));
        $this->assertNotNull(BillAdjustment::creditProblem(0, 1100));
    }

    public function test_never_more_than_the_service_charges(): void
    {
        $this->assertNull(BillAdjustment::creditProblem(400, 1100, 650));
        $this->assertNotNull(BillAdjustment::creditProblem(700, 1100, 650));
        // ₱650 already taken off it.
        $this->assertNotNull(BillAdjustment::creditProblem(10, 1100, 0));
    }

    public function test_a_voucher_after_payment_keeps_what_came_off_before(): void
    {
        // ₱1,000 bill, ₱100 taken off before payment, paid ₱900; a ₱200 voucher
        // makes it ₱700: ₱200 back.
        $this->assertSame(['discount' => 200.0, 'amount' => 700.0, 'give_back' => 200.0], BillAdjustment::voucher(1000, 0, 900, 'amount', 200, 100));
    }

    public function test_a_voucher_for_the_whole_bill_hands_everything_back(): void
    {
        $this->assertSame(['discount' => 1000.0, 'amount' => 0.0, 'give_back' => 1000.0], BillAdjustment::voucher(1000, 0, 1000, 'amount', 1500));
    }
}

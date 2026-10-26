<?php

namespace Tests\Unit;

use App\Support\BillAdjustment;
use App\Support\RefundPolicyRule;
use PHPUnit\Framework\TestCase;

// The owner's refund policies: how much each gives back, and when one has to be used.
class RefundPolicyRuleTest extends TestCase
{
    public function test_a_full_refund_is_everything_still_held(): void
    {
        $this->assertSame(1000.0, RefundPolicyRule::amount('full', null, 1000, 1000));
        // ₱300 already handed back: what is left.
        $this->assertSame(700.0, RefundPolicyRule::amount('full', null, 1000, 700));
    }

    public function test_a_share_is_of_what_was_paid(): void
    {
        $this->assertSame(500.0, RefundPolicyRule::amount('percent', 50, 1000, 1000));
        $this->assertSame(333.33, RefundPolicyRule::amount('percent', 33.333, 1000, 1000));
        // Never more than is still held.
        $this->assertSame(200.0, RefundPolicyRule::amount('percent', 50, 1000, 200));
    }

    public function test_a_set_amount_never_goes_over_what_is_held(): void
    {
        $this->assertSame(200.0, RefundPolicyRule::amount('fixed', 200, 1000, 1000));
        $this->assertSame(150.0, RefundPolicyRule::amount('fixed', 200, 1000, 150));
    }

    public function test_never_more_than_the_policy(): void
    {
        $this->assertNotNull(RefundPolicyRule::problem('percent', 50, true, 1000, 1000, 600));
        $this->assertNull(RefundPolicyRule::problem('percent', 50, true, 1000, 1000, 500));
    }

    public function test_less_only_when_the_policy_allows_it(): void
    {
        $this->assertNull(RefundPolicyRule::problem('fixed', 200, true, 1000, 1000, 50));
        $this->assertNotNull(RefundPolicyRule::problem('fixed', 200, false, 1000, 1000, 50));
        $this->assertNull(RefundPolicyRule::problem('fixed', 200, false, 1000, 1000, 200));
    }

    public function test_a_policy_with_nothing_left_to_give_is_refused(): void
    {
        $this->assertNotNull(RefundPolicyRule::problem('full', null, false, 1000, 0, 0));
    }

    public function test_refunds_outside_the_policies(): void
    {
        $this->assertNull(RefundPolicyRule::customProblem('business_owner', false, true));
        $this->assertNull(RefundPolicyRule::customProblem('front_officer', true, true));
        $this->assertNotNull(RefundPolicyRule::customProblem('front_officer', false, true));
        $this->assertNotNull(RefundPolicyRule::customProblem('manager', false, false));
    }

    public function test_how_a_policy_reads(): void
    {
        $this->assertSame('Everything paid', RefundPolicyRule::describe('full', null, false));
        $this->assertSame('Up to 50% of what was paid', RefundPolicyRule::describe('percent', 50, true));
        $this->assertSame('12.5% of what was paid', RefundPolicyRule::describe('percent', 12.5, false));
        $this->assertSame('₱200.00', RefundPolicyRule::describe('fixed', 200, false));
    }

    public function test_what_the_owner_types_is_checked(): void
    {
        $this->assertNull(RefundPolicyRule::invalid('full', null));
        $this->assertNotNull(RefundPolicyRule::invalid('percent', 0));
        $this->assertNotNull(RefundPolicyRule::invalid('percent', 120));
        $this->assertNotNull(RefundPolicyRule::invalid('fixed', null));
        $this->assertNotNull(RefundPolicyRule::invalid('other', 10));
    }

    public function test_a_policy_that_always_asks_sends_it_up_even_with_the_permission(): void
    {
        $this->assertSame(BillAdjustment::MANAGER, BillAdjustment::route('front_officer', true, true));
        $this->assertSame(BillAdjustment::OWNER, BillAdjustment::route('manager', true, true));
        // The owner answers to no one.
        $this->assertSame(BillAdjustment::NOW, BillAdjustment::route('business_owner', false, true));
        // Without it, the permission decides as before.
        $this->assertSame(BillAdjustment::NOW, BillAdjustment::route('front_officer', true, false));
    }
}

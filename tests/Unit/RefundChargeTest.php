<?php

namespace Tests\Unit;

use App\Support\RefundCharge;
use PHPUnit\Framework\TestCase;

// Who a refund comes out of: the business, the staff who did the visit, or both.
class RefundChargeTest extends TestCase
{
    public function test_the_business_bears_it(): void
    {
        $this->assertSame(['business' => 300.0, 'staff' => []], RefundCharge::split(300, 'business', null, [7 => 500]));
    }

    public function test_staff_bear_all_of_it(): void
    {
        $this->assertSame(['business' => 0.0, 'staff' => [7 => 300.0]], RefundCharge::split(300, 'staff', null, [7 => 500]));
    }

    public function test_both_split_by_the_staff_share(): void
    {
        $this->assertSame(['business' => 150.0, 'staff' => [7 => 150.0]], RefundCharge::split(300, 'both', 50, [7 => 500]));
        $this->assertSame(['business' => 225.0, 'staff' => [7 => 75.0]], RefundCharge::split(300, 'both', 25, [7 => 500]));
    }

    public function test_the_staff_part_is_shared_by_what_each_did(): void
    {
        // ₱1,000 of service by one, ₱500 by another: two thirds and one third of ₱300.
        $this->assertSame(['business' => 0.0, 'staff' => [7 => 200.0, 9 => 100.0]], RefundCharge::split(300, 'staff', null, [7 => 1000, 9 => 500]));
    }

    public function test_the_parts_add_up_to_the_centavo(): void
    {
        $split = RefundCharge::split(100, 'staff', null, [1 => 1, 2 => 1, 3 => 1]);
        $this->assertEqualsWithDelta(100.0, array_sum($split['staff']), 0.0001);
        $this->assertSame(33.34, max($split['staff']));
    }

    public function test_the_left_over_centavo_goes_where_rounding_says(): void
    {
        // ₱100 by ₱800 : ₱650 is ₱55.172… and ₱44.827…: ₱55.17 and ₱44.83.
        $this->assertSame([4 => 55.17, 3 => 44.83], RefundCharge::split(100, 'staff', null, [4 => 800, 3 => 650])['staff']);
        // ₱300 the same way is ₱165.517… and ₱134.482…: ₱165.52 and ₱134.48.
        $this->assertSame([4 => 165.52, 3 => 134.48], RefundCharge::split(300, 'staff', null, [4 => 800, 3 => 650])['staff']);
    }

    public function test_nobody_recorded_leaves_it_with_the_business(): void
    {
        $this->assertSame(['business' => 300.0, 'staff' => []], RefundCharge::split(300, 'staff', null, []));
        $this->assertSame(['business' => 300.0, 'staff' => []], RefundCharge::split(300, 'both', 50, [7 => 0]));
    }

    public function test_how_it_reads(): void
    {
        $this->assertSame('The business pays', RefundCharge::describe('business', null));
        $this->assertSame('Staff pay all of it', RefundCharge::describe('staff', null));
        $this->assertSame('Split equally between staff and the business', RefundCharge::describe('both', 50));
        $this->assertSame('Split equally between staff and the business', RefundCharge::describe('both', null));
    }

    public function test_equal_is_half_and_half(): void
    {
        $this->assertSame(50.0, RefundCharge::shareFor('both'));
        $this->assertNull(RefundCharge::shareFor('staff'));
        $this->assertSame(['business' => 150.0, 'staff' => [7 => 150.0]], RefundCharge::split(300, 'both', RefundCharge::shareFor('both'), [7 => 500]));
    }

    public function test_only_the_three_choices(): void
    {
        $this->assertNull(RefundCharge::invalid('business'));
        $this->assertNull(RefundCharge::invalid('staff'));
        $this->assertNull(RefundCharge::invalid('both'));
        $this->assertNotNull(RefundCharge::invalid('nobody'));
    }
}

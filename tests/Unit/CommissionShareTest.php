<?php

namespace Tests\Unit;

use App\Support\CommissionShare;
use PHPUnit\Framework\TestCase;

// What a therapist earns for one completed service.
class CommissionShareTest extends TestCase
{
    private function policy(array $over = []): array
    {
        return $over + ['commission_enabled' => true, 'commission_type' => 'percentage', 'default_commission_rate' => 40, 'commission_applies_to' => 'both'];
    }

    public function test_a_percentage_of_the_services_price(): void
    {
        $this->assertSame(320.0, CommissionShare::amount($this->policy(), 800.0, 800.0, false));
    }

    public function test_a_fixed_amount_per_service_ignores_the_price(): void
    {
        $policy = $this->policy(['commission_type' => 'fixed', 'default_commission_rate' => 150]);

        $this->assertSame(150.0, CommissionShare::amount($policy, 800.0, 800.0, false));
        $this->assertSame(150.0, CommissionShare::amount($policy, 2400.0, 2400.0, false));
    }

    public function test_it_is_shared_equally_by_everyone_who_completed_it(): void
    {
        $this->assertSame(160.0, CommissionShare::amount($this->policy(), 800.0, 800.0, false, 2));
        $this->assertSame(75.0, CommissionShare::amount($this->policy(['commission_type' => 'fixed', 'default_commission_rate' => 150]), 800.0, 800.0, false, 2));
    }

    public function test_commission_switched_off_earns_nothing(): void
    {
        $this->assertSame(0.0, CommissionShare::amount(null, 800.0, 800.0, false));
        $this->assertSame(0.0, CommissionShare::amount($this->policy(['commission_enabled' => false]), 800.0, 800.0, false));
    }

    public function test_applies_to_decides_whether_services_or_package_lines_earn(): void
    {
        $services = $this->policy(['commission_applies_to' => 'service']);
        $packages = $this->policy(['commission_applies_to' => 'package']);

        $this->assertSame(320.0, CommissionShare::amount($services, 800.0, 800.0, false));
        $this->assertSame(0.0, CommissionShare::amount($services, 800.0, 800.0, true));
        $this->assertSame(0.0, CommissionShare::amount($packages, 800.0, 800.0, false));
        $this->assertSame(320.0, CommissionShare::amount($packages, 800.0, 800.0, true));
    }

    public function test_a_line_with_no_price_of_its_own_uses_its_list_price(): void
    {
        $this->assertSame(100.0, CommissionShare::amount($this->policy(), 0.0, 250.0, true));
    }

    public function test_what_was_earned_counts_only_once_the_visit_is_paid_in_full(): void
    {
        $this->assertTrue(CommissionShare::paidFor('Paid'));
    }

    public function test_a_visit_not_paid_yet_shows_nothing(): void
    {
        // Done, but checkout hasn't happened: no bill at all.
        $this->assertFalse(CommissionShare::paidFor(null));
        // Billed and unpaid, part-paid, or reopened by a voided payment.
        $this->assertFalse(CommissionShare::paidFor('Pending'));
        $this->assertFalse(CommissionShare::paidFor('Overdue'));
    }

    public function test_a_payment_taken_back_shows_nothing_either(): void
    {
        $this->assertFalse(CommissionShare::paidFor('Refunded'));
        $this->assertFalse(CommissionShare::paidFor('Cancelled'));
    }

    // ── A commission of their own ───────────────────────────────────────

    public function test_a_custom_rate_replaces_the_standard_one(): void
    {
        $this->assertSame(160.0, CommissionShare::amount($this->policy(), 800.0, 800.0, false, 1, 20.0));
        // None of their own: the standard.
        $this->assertSame(320.0, CommissionShare::amount($this->policy(), 800.0, 800.0, false, 1, null));
        // A custom rate of nothing is still a custom rate.
        $this->assertSame(0.0, CommissionShare::amount($this->policy(), 800.0, 800.0, false, 1, 0.0));
    }

    public function test_a_custom_rate_is_the_same_kind_as_the_standard(): void
    {
        $fixed = $this->policy(['commission_type' => 'fixed', 'default_commission_rate' => 150]);

        // Pesos per service while the standard is fixed…
        $this->assertSame(200.0, CommissionShare::amount($fixed, 800.0, 800.0, false, 1, 200.0));
        // …a percentage while it is a percentage.
        $this->assertSame(200.0, CommissionShare::amount($this->policy(), 800.0, 800.0, false, 1, 25.0));
    }

    public function test_a_shared_service_pays_each_at_their_own_rate(): void
    {
        // Two did it: the senior at 50%, the other at the standard 40%.
        $this->assertSame(200.0, CommissionShare::amount($this->policy(), 800.0, 800.0, false, 2, 50.0));
        $this->assertSame(160.0, CommissionShare::amount($this->policy(), 800.0, 800.0, false, 2));
    }

    public function test_a_custom_rate_changes_nothing_else(): void
    {
        // Commission off pays nobody, custom rate or not.
        $this->assertSame(0.0, CommissionShare::amount(null, 800.0, 800.0, false, 1, 50.0));
        // Neither does a line the policy doesn't apply to.
        $this->assertSame(0.0, CommissionShare::amount($this->policy(['commission_applies_to' => 'service']), 800.0, 800.0, true, 1, 50.0));
        // A line with no price of its own still uses its list price.
        $this->assertSame(125.0, CommissionShare::amount($this->policy(), 0.0, 250.0, true, 1, 50.0));
    }

    public function test_a_custom_rate_being_saved(): void
    {
        // Blank is "the standard".
        $this->assertSame([null, null], CommissionShare::clean(null, 'percentage'));
        $this->assertSame([null, null], CommissionShare::clean('', 'percentage'));

        $this->assertSame([20.0, null], CommissionShare::clean('20', 'percentage'));
        $this->assertSame([12.5, null], CommissionShare::clean(12.5, 'percentage'));
        $this->assertSame([0.0, null], CommissionShare::clean(0, 'percentage'));
        $this->assertSame([250.0, null], CommissionShare::clean(250, 'fixed'));

        // A percentage stops at 100; pesos don't.
        $this->assertSame('A percentage commission can be at most 100%.', CommissionShare::clean(250, 'percentage')[1]);
        $this->assertSame('Enter a commission of 0 or more.', CommissionShare::clean(-5, 'percentage')[1]);
        $this->assertSame('Enter a commission of 0 or more.', CommissionShare::clean('lots', 'fixed')[1]);
    }

    public function test_how_a_commission_reads(): void
    {
        $this->assertSame('20%', CommissionShare::label(20.0, 'percentage'));
        $this->assertSame('12.5%', CommissionShare::label(12.5, 'percentage'));
        $this->assertSame('100%', CommissionShare::label(100.0, 'percentage'));
        $this->assertSame('₱150 per service', CommissionShare::label(150.0, 'fixed'));
        $this->assertSame('₱1,250.50 per service', CommissionShare::label(1250.5, 'fixed'));

        $this->assertSame('Standard (40%)', CommissionShare::describe($this->policy(), null));
        $this->assertSame('20%', CommissionShare::describe($this->policy(), 20.0));
        $this->assertSame('Standard (₱150 per service)', CommissionShare::describe($this->policy(['commission_type' => 'fixed', 'default_commission_rate' => 150]), null));
    }
}

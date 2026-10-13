<?php

namespace Tests\Unit;

use App\Support\ServiceVoidRules;
use PHPUnit\Framework\TestCase;

// Taking a started or finished service off a visit.
class ServiceVoidRulesTest extends TestCase
{
    private function bill(array $over = []): array
    {
        return $over + ['status' => 'Pending', 'kept' => 0.0, 'discount' => 0.0];
    }

    public function test_a_started_or_finished_service_can_be_voided_while_the_visit_is_open_and_unbilled(): void
    {
        $this->assertNull(ServiceVoidRules::problem('In Progress', 'in_service', null, false));
        $this->assertNull(ServiceVoidRules::problem('Completed', 'in_service', null, false));
        // The only service on the visit, with no bill yet: allowed — the visit can then be cancelled.
        $this->assertNull(ServiceVoidRules::problem('Completed', 'in_service', null, true));
    }

    public function test_an_unpaid_bill_with_no_discount_does_not_stand_in_the_way(): void
    {
        $this->assertNull(ServiceVoidRules::problem('Completed', 'in_service', $this->bill(), false));
    }

    public function test_one_already_removed_cannot_be_voided_again(): void
    {
        $this->assertSame('This service has already been removed.', ServiceVoidRules::problem('Cancelled', 'in_service', null, false));
    }

    public function test_a_closed_visit_is_refused(): void
    {
        $this->assertStringContainsString('cancelled', (string) ServiceVoidRules::problem('Completed', 'cancelled', null, false));
        $this->assertStringContainsString('cancelled', (string) ServiceVoidRules::problem('Completed', 'no_show', null, false));
        $this->assertStringContainsString('finished and paid', (string) ServiceVoidRules::problem('Completed', 'completed', $this->bill(['status' => 'Paid', 'kept' => 800]), false));
    }

    public function test_a_payment_already_taken_is_refused(): void
    {
        $problem = ServiceVoidRules::problem('Completed', 'in_service', $this->bill(['kept' => 200]), false);

        $this->assertSame('A payment has already been taken on this bill. Void that payment first, or use a refund instead.', $problem);
    }

    public function test_a_discount_on_the_bill_has_to_come_off_first(): void
    {
        $problem = ServiceVoidRules::problem('Completed', 'in_service', $this->bill(['discount' => 160]), false);

        $this->assertStringStartsWith('Remove the discount on the bill first', (string) $problem);
    }

    public function test_the_only_charge_on_a_bill_cannot_be_voided(): void
    {
        $problem = ServiceVoidRules::problem('Completed', 'in_service', $this->bill(), true);

        $this->assertSame('This is the only charge on the bill, so nothing would be left to bill.', $problem);
    }

    public function test_a_closed_bill_is_refused(): void
    {
        foreach (['Paid', 'Refunded', 'Cancelled'] as $status) {
            $this->assertStringContainsString('closed', (string) ServiceVoidRules::problem('Completed', 'in_service', $this->bill(['status' => $status]), false));
        }
    }

    public function test_the_note_keeps_who_why_and_what_was_written_before(): void
    {
        $this->assertSame('Voided by Janine Soriano: Added by mistake', ServiceVoidRules::note(' Added by mistake ', 'Janine Soriano'));
        $this->assertSame(
            'Voided by Janine Soriano: Client changed their mind · Note: light pressure',
            ServiceVoidRules::note('Client changed their mind', 'Janine Soriano', 'light pressure'),
        );
    }
}

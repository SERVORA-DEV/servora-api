<?php

namespace Tests\Unit;

use App\Support\ServiceRefunds;
use PHPUnit\Framework\TestCase;

class ServiceRefundsTest extends TestCase
{
    private function lines(): array
    {
        return [
            ['id' => 1, 'charge' => 500.0, 'refunded' => 0.0],
            ['id' => 2, 'charge' => 1500.0, 'refunded' => 0.0],
        ];
    }

    public function test_a_paid_bill_is_refundable_service_by_service(): void
    {
        $plan = ServiceRefunds::plan($this->lines(), 0.0, 0.0, 2000.0, 2000.0);

        $this->assertSame(500.0, $plan[1]['refundable']);
        $this->assertSame(1500.0, $plan[2]['refundable']);
    }

    public function test_a_part_paid_bill_is_paid_in_the_same_proportion_on_every_service(): void
    {
        $plan = ServiceRefunds::plan($this->lines(), 0.0, 0.0, 1000.0, 1000.0);

        $this->assertSame(250.0, $plan[1]['refundable']);
        $this->assertSame(750.0, $plan[2]['refundable']);
    }

    public function test_a_bill_level_discount_comes_off_every_service_pro_rata(): void
    {
        // ₱2,000 less ₱400 = ₱1,600 owed and paid: 80% of each service.
        $plan = ServiceRefunds::plan($this->lines(), 0.0, 400.0, 1600.0, 1600.0);

        $this->assertSame(400.0, $plan[1]['refundable']);
        $this->assertSame(1200.0, $plan[2]['refundable']);
    }

    public function test_money_already_given_back_for_a_service_comes_off_it(): void
    {
        $lines = $this->lines();
        $lines[0]['refunded'] = 500.0;
        $plan = ServiceRefunds::plan($lines, 0.0, 0.0, 2000.0, 1500.0);

        $this->assertSame(0.0, $plan[1]['refundable']);
        $this->assertSame(1500.0, $plan[2]['refundable']);
    }

    public function test_the_services_never_show_more_than_the_bill_still_keeps(): void
    {
        // Half the bill was handed back as a whole, with no service named.
        $plan = ServiceRefunds::plan($this->lines(), 0.0, 0.0, 2000.0, 1000.0);

        $this->assertEqualsWithDelta(1000.0, $plan[1]['refundable'] + $plan[2]['refundable'], 0.01);
    }

    public function test_nothing_is_refundable_when_nothing_was_paid(): void
    {
        $plan = ServiceRefunds::plan($this->lines(), 0.0, 0.0, 0.0, 0.0);

        $this->assertSame(0.0, $plan[1]['refundable']);
        $this->assertSame(0.0, $plan[2]['refundable']);
    }
}

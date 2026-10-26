<?php

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\CustomerProgram;
use App\Support\ProgramChannel;
use PHPUnit\Framework\TestCase;

// Where a customer program applies: everywhere, the mobile app only, or the
// front desk only. No database.
//   php artisan test --filter=ProgramChannelTest
class ProgramChannelTest extends TestCase
{
    private function program(?string $channel): CustomerProgram
    {
        return new CustomerProgram(['values' => $channel === null ? [] : ['channel' => $channel]]);
    }

    public function test_a_program_with_no_channel_is_for_everywhere(): void
    {
        $this->assertSame('all', ProgramChannel::of($this->program(null)));
        $this->assertSame('all', ProgramChannel::of($this->program('nonsense')));
        $this->assertTrue(ProgramChannel::allows('all', 'app'));
        $this->assertTrue(ProgramChannel::allows('all', 'front_desk'));
    }

    public function test_a_limited_program_applies_only_where_it_says(): void
    {
        $this->assertTrue(ProgramChannel::allows(ProgramChannel::of($this->program('app')), 'app'));
        $this->assertFalse(ProgramChannel::allows(ProgramChannel::of($this->program('app')), 'front_desk'));
        $this->assertTrue(ProgramChannel::allows(ProgramChannel::of($this->program('front_desk')), 'front_desk'));
        $this->assertFalse(ProgramChannel::allows(ProgramChannel::of($this->program('front_desk')), 'app'));
    }

    public function test_a_booking_from_the_app_is_the_app_and_anything_else_is_the_desk(): void
    {
        $this->assertSame('app', ProgramChannel::whereOf(new Appointment(['source' => 'Mobile'])));
        $this->assertSame('front_desk', ProgramChannel::whereOf(new Appointment(['source' => 'Front Desk'])));
        $this->assertSame('front_desk', ProgramChannel::whereOf(null));
    }

    public function test_a_voucher_snapshot_array_is_read_too(): void
    {
        $this->assertSame('app', ProgramChannel::of(['channel' => 'app']));
        $this->assertSame('front_desk', ProgramChannel::of(['values' => ['channel' => 'front_desk']]));
    }
}

<?php

namespace Tests\Unit;

use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use App\Support\ReminderPolicy;
use PHPUnit\Framework\TestCase;

// What a branch tells its clients and when: its own rules, else the
// business's. No database.
class ReminderPolicyTest extends TestCase
{
    private array $business = [
        'send_client_reminder' => true, 'reminder_timing' => 24, 'send_payment_reminder' => true, 'in_app_channel' => true,
    ];

    public function test_a_branch_without_overrides_follows_the_business(): void
    {
        $policy = ReminderPolicy::resolve($this->business, []);

        $this->assertTrue(ReminderPolicy::remindsClients($policy));
        $this->assertSame(24, $policy['reminder_timing']);
        $this->assertTrue(ReminderPolicy::remindsPayments($policy));
    }

    public function test_the_branchs_own_rules_win(): void
    {
        $policy = ReminderPolicy::resolve($this->business, ['reminder_timing' => 4, 'send_payment_reminder' => false]);

        $this->assertSame(4, $policy['reminder_timing']);
        $this->assertTrue(ReminderPolicy::remindsClients($policy));
        $this->assertFalse(ReminderPolicy::remindsPayments($policy));
    }

    public function test_a_branch_can_turn_reminders_off_or_on_against_the_business(): void
    {
        $off = ReminderPolicy::resolve($this->business, ['send_client_reminder' => false]);
        $this->assertFalse(ReminderPolicy::remindsClients($off));

        $on = ReminderPolicy::resolve(['send_client_reminder' => false] + $this->business, ['send_client_reminder' => true]);
        $this->assertTrue(ReminderPolicy::remindsClients($on));
    }

    public function test_nothing_is_sent_without_the_in_app_channel(): void
    {
        $policy = ReminderPolicy::resolve(['in_app_channel' => false] + $this->business, ['send_client_reminder' => true]);

        $this->assertFalse(ReminderPolicy::remindsClients($policy));
        $this->assertFalse(ReminderPolicy::remindsPayments($policy));
    }

    public function test_a_lead_time_that_is_not_offered_falls_back_to_the_default(): void
    {
        $this->assertSame(24, ReminderPolicy::resolve($this->business, ['reminder_timing' => 5])['reminder_timing']);
        $this->assertSame(48, ReminderPolicy::resolve(['reminder_timing' => '48'] + $this->business, [])['reminder_timing']);
    }

    public function test_missing_business_settings_use_the_defaults(): void
    {
        $policy = ReminderPolicy::resolve([], []);

        $this->assertSame(SpaBusinessSetting::DEFAULTS['notifications']['reminder_timing'], $policy['reminder_timing']);
        $this->assertTrue(ReminderPolicy::remindsClients($policy));
    }

    public function test_for_reads_the_branch_and_its_business(): void
    {
        $business = new SpaBusiness();
        $business->setRelation('settings', new SpaBusinessSetting(['notifications' => ['reminder_timing' => 12]]));
        $branch = new SpaBranch(['booking_overrides' => ['send_payment_reminder' => false]]);
        $branch->setRelation('business', $business);

        $policy = ReminderPolicy::for($branch);

        $this->assertSame(12, $policy['reminder_timing']);
        $this->assertFalse($policy['send_payment_reminder']);
    }
}

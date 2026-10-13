<?php

namespace Tests\Unit;

use App\Support\FrontDeskPolicy;
use App\Support\PolicyOverride;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

// The owner's and manager's booking rules, as the front desk is held to them.
class FrontDeskPolicyTest extends TestCase
{
    private function policy(array $over = []): array
    {
        return $over + [
            'reservation_enabled' => true,
            'booking_lead_time_minutes' => 30, 'max_advance_booking_days' => 30,
            'max_services_per_booking' => 3, 'max_service_minutes_per_booking' => 0,
            'cancellation_window_hours' => 24,
            'no_show_policy' => 'warn', 'no_show_suspend_after' => 3,
            'queue_enabled' => true, 'max_queue_size' => 20,
            'allow_service_extension' => true, 'max_extension_mins' => 60, 'extension_requires_approval' => false,
        ];
    }

    private function now(): Carbon
    {
        return Carbon::parse('2026-10-10 10:00:00');
    }

    private function rules(array $problems): array
    {
        return array_column($problems, 'rule');
    }

    // ── Reservations ─────────────────────────────────────────────────────────

    public function test_a_reservation_inside_every_rule_goes_through(): void
    {
        $this->assertSame([], FrontDeskPolicy::reservation($this->policy(), $this->now()->addHours(3), $this->now(), 2, 120));
    }

    public function test_reservations_switched_off_is_a_refusal_nobody_can_override(): void
    {
        $problems = FrontDeskPolicy::reservation($this->policy(['reservation_enabled' => false]), $this->now()->addDay(), $this->now());

        $this->assertSame(['reservations_off'], $this->rules($problems));
        $this->assertFalse($problems[0]['overridable']);
    }

    public function test_too_short_a_notice_says_how_short(): void
    {
        $problems = FrontDeskPolicy::reservation($this->policy(), $this->now()->addMinutes(10), $this->now());

        $this->assertSame(['lead_time'], $this->rules($problems));
        $this->assertSame('Bookings need 30 minutes of notice — this one starts in 10 minutes.', $problems[0]['message']);
        $this->assertTrue($problems[0]['overridable']);
    }

    public function test_exactly_the_notice_is_enough(): void
    {
        $this->assertSame([], FrontDeskPolicy::reservation($this->policy(), $this->now()->addMinutes(30), $this->now()));
    }

    public function test_too_far_ahead(): void
    {
        $problems = FrontDeskPolicy::reservation($this->policy(), $this->now()->addDays(45), $this->now());

        $this->assertSame(['max_advance'], $this->rules($problems));
        $this->assertSame('Bookings can be made up to 30 days ahead — this one is 45 days away.', $problems[0]['message']);
        $this->assertSame([], FrontDeskPolicy::reservation($this->policy(), $this->now()->addDays(30), $this->now()));
    }

    public function test_too_many_services_and_too_much_time(): void
    {
        $problems = FrontDeskPolicy::reservation($this->policy(['max_service_minutes_per_booking' => 180]), $this->now()->addHours(3), $this->now(), 4, 240);

        $this->assertSame(['max_services', 'service_time'], $this->rules($problems));
        $this->assertSame('A booking can hold up to 3 hours of services here — these take 4 hours.', $problems[1]['message']);
    }

    public function test_no_limits_set_means_no_limits(): void
    {
        $open = $this->policy(['booking_lead_time_minutes' => 0, 'max_advance_booking_days' => 0, 'max_services_per_booking' => 0]);

        $this->assertSame([], FrontDeskPolicy::reservation($open, $this->now()->addMinutes(1), $this->now(), 9, 900));
        $this->assertSame([], FrontDeskPolicy::reservation($open, $this->now()->addDays(300), $this->now()));
    }

    public function test_a_client_suspended_for_no_shows_needs_an_override(): void
    {
        $policy = $this->policy(['no_show_policy' => 'suspend']);
        $at = $this->now()->addHours(3);

        $this->assertSame(['client_suspended'], $this->rules(FrontDeskPolicy::reservation($policy, $at, $this->now(), 1, 60, ['name' => 'Maria Santos', 'no_shows' => 3])));
        $this->assertSame([], FrontDeskPolicy::reservation($policy, $at, $this->now(), 1, 60, ['name' => 'Maria Santos', 'no_shows' => 2]));
        // Only under the "suspend" policy.
        $this->assertSame([], FrontDeskPolicy::reservation($this->policy(), $at, $this->now(), 1, 60, ['name' => 'Maria Santos', 'no_shows' => 9]));
    }

    // ── Cancelling and moving ────────────────────────────────────────────────

    public function test_a_change_inside_the_cancellation_window_is_late(): void
    {
        $problems = FrontDeskPolicy::change($this->policy(), $this->now()->addHours(2), $this->now(), 'cancel');

        $this->assertSame(['late_change'], $this->rules($problems));
        $this->assertSame('Bookings can be cancelled up to 24 hours before they start — this one starts in 2 hours.', $problems[0]['message']);
        $this->assertStringContainsString('moved', FrontDeskPolicy::change($this->policy(), $this->now()->addHours(2), $this->now(), 'reschedule')[0]['message']);
    }

    public function test_a_change_outside_the_window_or_with_no_window_is_fine(): void
    {
        $this->assertSame([], FrontDeskPolicy::change($this->policy(), $this->now()->addHours(24), $this->now(), 'cancel'));
        $this->assertSame([], FrontDeskPolicy::change($this->policy(['cancellation_window_hours' => 0]), $this->now()->addHour(), $this->now(), 'cancel'));
    }

    public function test_a_booking_whose_time_has_passed_can_simply_be_cancelled(): void
    {
        $this->assertSame([], FrontDeskPolicy::change($this->policy(), $this->now()->subMinutes(20), $this->now(), 'cancel'));
    }

    // ── Queue ────────────────────────────────────────────────────────────────

    public function test_a_full_queue_refuses_one_more(): void
    {
        $this->assertSame([], FrontDeskPolicy::queue($this->policy(), 19));
        $this->assertSame(['queue_full'], $this->rules(FrontDeskPolicy::queue($this->policy(), 20)));
    }

    public function test_the_size_limit_only_counts_when_the_queue_is_on(): void
    {
        $this->assertSame([], FrontDeskPolicy::queue($this->policy(['queue_enabled' => false]), 99));
    }

    // ── Extending a service ──────────────────────────────────────────────────

    public function test_extension_switched_off_cannot_be_overridden(): void
    {
        $problems = FrontDeskPolicy::extension($this->policy(['allow_service_extension' => false]), 0, 30);

        $this->assertSame(['extension_off'], $this->rules($problems));
        $this->assertFalse($problems[0]['overridable']);
    }

    public function test_the_longest_extension_counts_what_was_already_added(): void
    {
        $this->assertSame([], FrontDeskPolicy::extension($this->policy(), 0, 60));
        $this->assertSame([], FrontDeskPolicy::extension($this->policy(), 30, 30));

        $problems = FrontDeskPolicy::extension($this->policy(), 30, 60);
        $this->assertSame(['max_extension'], $this->rules($problems));
        $this->assertSame('A service can be extended by up to 1 hour — this would make it 1 hour 30 minutes.', $problems[0]['message']);
    }

    public function test_needs_approval_makes_every_extension_an_override(): void
    {
        $this->assertSame(['extension_approval'], $this->rules(FrontDeskPolicy::extension($this->policy(['extension_requires_approval' => true]), 0, 30)));
    }

    // ── No-shows ─────────────────────────────────────────────────────────────

    public function test_what_a_no_show_leads_to(): void
    {
        $this->assertSame('none', FrontDeskPolicy::noShowAction($this->policy(['no_show_policy' => 'none']), 5));
        $this->assertSame('warn', FrontDeskPolicy::noShowAction($this->policy(), 5));
        $this->assertSame('warn', FrontDeskPolicy::noShowAction($this->policy(['no_show_policy' => 'suspend']), 2));
        $this->assertSame('suspend', FrontDeskPolicy::noShowAction($this->policy(['no_show_policy' => 'suspend']), 3));
        $this->assertTrue(FrontDeskPolicy::clientSuspended($this->policy(['no_show_policy' => 'suspend']), 3));
        $this->assertFalse(FrontDeskPolicy::clientSuspended($this->policy(), 3));
    }

    // ── Refused, with a recorded override ────────────────────────────────────

    public function test_nothing_broken_needs_nothing(): void
    {
        $this->assertSame(['ok' => true, 'message' => null, 'rules' => [], 'can_override' => false, 'kept' => []], PolicyOverride::decide([], null, false));
    }

    public function test_without_the_permission_it_is_refused_and_says_who_can(): void
    {
        $problems = FrontDeskPolicy::reservation($this->policy(), $this->now()->addMinutes(10), $this->now());
        $decision = PolicyOverride::decide($problems, 'Regular client, called ahead', false);

        $this->assertFalse($decision['ok']);
        $this->assertFalse($decision['can_override']);
        $this->assertStringEndsWith('It needs someone allowed to override booking rules.', $decision['message']);
    }

    public function test_with_the_permission_a_reason_is_still_needed(): void
    {
        $problems = FrontDeskPolicy::reservation($this->policy(), $this->now()->addMinutes(10), $this->now());

        foreach ([null, '', ' ok '] as $reason) {
            $decision = PolicyOverride::decide($problems, $reason, true);
            $this->assertFalse($decision['ok']);
            $this->assertTrue($decision['can_override']);
        }
    }

    public function test_with_the_permission_and_a_reason_it_goes_ahead_and_keeps_both(): void
    {
        $problems = FrontDeskPolicy::reservation($this->policy(), $this->now()->addMinutes(10), $this->now(), 4, 60);
        $decision = PolicyOverride::decide($problems, ' Regular client, called ahead ', true);

        $this->assertTrue($decision['ok']);
        $this->assertSame(['lead_time', 'max_services'], array_column($decision['kept'], 'rule'));
        $this->assertSame('Regular client, called ahead', $decision['kept'][0]['reason']);
    }

    public function test_a_switch_in_settings_cannot_be_overridden_by_anyone(): void
    {
        $problems = FrontDeskPolicy::reservation($this->policy(['reservation_enabled' => false]), $this->now()->addDay(), $this->now());
        $decision = PolicyOverride::decide($problems, 'The owner said so', true);

        $this->assertFalse($decision['ok']);
        $this->assertFalse($decision['can_override']);
        $this->assertStringStartsWith('Reservations are switched off', $decision['message']);
    }
}

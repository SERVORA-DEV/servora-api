<?php

namespace Tests\Unit;

use App\Http\Requests\Business\BranchSettings\BranchBookingPolicyRequest;
use App\Models\SpaBranch;
use App\Models\SpaBusinessSetting;
use App\Service\Client\BookingPolicy;
use PHPUnit\Framework\TestCase;

// Which settings reach the client app, and which a branch may override. No database.
class ClientBookingPolicyKeysTest extends TestCase
{
    public function test_taking_bookings_choosing_staff_and_assigning_staff_are_always_on(): void
    {
        foreach (['online_booking_enabled', 'reservation_enabled', 'allow_therapist_selection', 'auto_assign_therapist'] as $key) {
            $this->assertNotContains($key, BookingPolicy::KEYS, $key);
            $this->assertNotContains($key, BranchBookingPolicyRequest::KEYS, $key);
            $this->assertArrayNotHasKey($key, SpaBusinessSetting::DEFAULTS['booking_defaults'], $key);
        }
    }

    public function test_a_branch_can_override_the_client_reminder_rules(): void
    {
        foreach (['send_client_reminder', 'reminder_timing', 'send_payment_reminder'] as $key) {
            $this->assertContains($key, BranchBookingPolicyRequest::KEYS);
            $this->assertArrayHasKey($key, SpaBusinessSetting::DEFAULTS['notifications']);
        }
    }

    public function test_every_branch_override_has_a_default_to_fall_back_to(): void
    {
        $known = SpaBusinessSetting::DEFAULTS['booking_defaults'] + SpaBusinessSetting::DEFAULTS['notifications'];
        foreach (BranchBookingPolicyRequest::KEYS as $key) {
            $this->assertArrayHasKey($key, $known, $key);
        }
    }

    public function test_the_wait_time_and_listing_toggles_that_are_always_on_are_gone(): void
    {
        $this->assertArrayNotHasKey('estimated_wait_display', SpaBusinessSetting::DEFAULTS['booking_defaults']);
        $this->assertNotContains('estimated_wait_display', BookingPolicy::KEYS);

        foreach (['show_prices', 'show_available_slots', 'show_room_availability', 'allow_promo_codes'] as $key) {
            $this->assertArrayNotHasKey($key, SpaBranch::DISPLAY_DEFAULTS);
        }
        $this->assertArrayHasKey('show_therapist_profiles', SpaBranch::DISPLAY_DEFAULTS);
    }

    public function test_walk_ins_the_refund_tier_and_lead_time_are_not_settings(): void
    {
        foreach (["walk_in_enabled", "booking_lead_time_minutes", "cancellation_policy_tier", "cancellation_tiers"] as $key) {
            $this->assertArrayNotHasKey($key, SpaBusinessSetting::DEFAULTS["booking_defaults"], $key);
            $this->assertNotContains($key, BranchBookingPolicyRequest::KEYS, $key);
        }
        $this->assertNotContains("walk_in_enabled", BookingPolicy::KEYS);
        $this->assertSame(60, BookingPolicy::LEAD_TIME_MINUTES);
    }

    public function test_a_booking_has_to_be_made_an_hour_ahead(): void
    {
        $policy = ["booking_lead_time_minutes" => BookingPolicy::LEAD_TIME_MINUTES, "max_advance_booking_days" => 30];
        $now = BookingPolicy::now();

        $this->assertSame("Please book at least 1 hour ahead.", BookingPolicy::startTimeProblem($policy, $now->copy()->addMinutes(45)));
        $this->assertNull(BookingPolicy::startTimeProblem($policy, $now->copy()->addMinutes(75)));
    }

    // The notice is the owner's again: "book at least N hours ahead", company-wide
    // with a per-branch override; one hour until they say otherwise.
    public function test_the_notice_a_booking_needs_is_a_setting_in_hours_defaulting_to_one(): void
    {
        $this->assertSame(1, SpaBusinessSetting::DEFAULTS['booking_defaults']['booking_lead_time_hours']);
        $this->assertContains('booking_lead_time_hours', BookingPolicy::KEYS);
        $this->assertContains('booking_lead_time_hours', BranchBookingPolicyRequest::KEYS);
    }

    public function test_a_longer_notice_says_how_long(): void
    {
        $policy = ['booking_lead_time_minutes' => 3 * 60, 'max_advance_booking_days' => 30];
        $now = BookingPolicy::now();

        $this->assertSame('Please book at least 3 hours ahead.', BookingPolicy::startTimeProblem($policy, $now->copy()->addMinutes(150)));
        $this->assertNull(BookingPolicy::startTimeProblem($policy, $now->copy()->addMinutes(190)));
    }

    public function test_no_notice_means_any_time_from_now_on(): void
    {
        $policy = ['booking_lead_time_minutes' => 0, 'max_advance_booking_days' => 30];
        $now = BookingPolicy::now();

        $this->assertNull(BookingPolicy::startTimeProblem($policy, $now->copy()->addMinutes(5)));
        $this->assertSame('Please choose a future time.', BookingPolicy::startTimeProblem($policy, $now->copy()->subMinutes(5)));
    }
}

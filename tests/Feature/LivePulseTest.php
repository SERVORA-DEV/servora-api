<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Billing;
use App\Models\Client;
use App\Models\Notification;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\StaffTimeOff;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\LivePulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The live pulse (App\Support\LivePulse, GET /business/live/pulse): one token per
// area plus an overall one, so an open screen reads again only when something it
// shows changed — a payment doesn't make the staff list read itself again.
//
// Needs PostgreSQL (see phpunit.xml):
//   php artisan test --filter=LivePulseTest
class LivePulseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private SpaBusiness $business;
    private SpaBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);
        $plan = SubscriptionPlan::create([
            'category' => 'Basic', 'name' => 'Basic', 'monthly_price' => 500, 'billing_cycle' => 'Monthly',
            'max_branches' => 5, 'max_user_accounts' => 10, 'is_active' => true,
        ]);
        Subscription::create([
            'spa_business_id' => $this->business->id, 'subscription_plan_id' => $plan->id, 'billing_cycle' => 'Monthly',
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(), 'status' => 'Active',
        ]);
    }

    /** @return array{token: string, topics: array<string, string>} */
    private function pulse(): array
    {
        return ['token' => LivePulse::token($this->business->id), 'topics' => LivePulse::topics($this->business->id)];
    }

    /** The areas whose token changed between two readings. */
    private function changed(array $before, array $after): array
    {
        return array_keys(array_filter($after['topics'], fn ($v, $t) => $v !== $before['topics'][$t], ARRAY_FILTER_USE_BOTH));
    }

    public function test_the_endpoint_answers_the_overall_token_and_one_per_area(): void
    {
        Sanctum::actingAs($this->owner);

        $answer = $this->getJson('/api/business/live/pulse')->assertOk()->json();

        $this->assertIsString($answer['token']);
        $this->assertSame(LivePulse::TOPICS, array_keys($answer['topics']));
        // Asking again changes nothing.
        $this->assertSame($answer, $this->getJson('/api/business/live/pulse')->json());
    }

    public function test_each_write_changes_its_own_area_and_the_overall_token_only(): void
    {
        $cases = [
            'billing' => fn () => Billing::create([
                'spa_business_id' => $this->business->id, 'spa_branch_id' => $this->branch->id, 'billing_type' => 'Appointment',
                'billing_number' => 'BIL-'.uniqid(), 'amount' => 500, 'status' => 'Pending', 'issued_at' => now(),
            ]),
            'setup' => fn () => Staff::factory()->create(['spa_branch_id' => $this->branch->id]),
            'clients' => fn () => Client::factory()->create(['spa_business_id' => $this->business->id]),
            'attendance' => fn () => Attendance::create([
                'staff_id' => Staff::where('spa_branch_id', $this->branch->id)->value('id'), 'attendance_date' => now()->toDateString(),
                'check_in_at' => now(), 'status' => 'Present',
            ]),
            'plan' => fn () => Subscription::where('spa_business_id', $this->business->id)->first()->update(['auto_renew' => true]),
        ];

        foreach ($cases as $area => $write) {
            $before = $this->pulse();
            $write();
            $after = $this->pulse();

            $this->assertNotSame($before['token'], $after['token'], "$area: the overall token changes");
            $this->assertSame([$area], $this->changed($before, $after), "$area: only its own area changes");
        }
    }

    // Time off is shown on the attendance screens and on the staff's schedule.
    public function test_time_off_changes_attendance_and_setup(): void
    {
        $staff = Staff::factory()->create(['spa_branch_id' => $this->branch->id]);
        $before = $this->pulse();

        StaffTimeOff::create(['staff_id' => $staff->id, 'type' => 'leave', 'starts_on' => now()->toDateString(), 'ends_on' => now()->toDateString()]);

        $after = $this->pulse();
        $this->assertSame(['attendance', 'setup'], $this->changed($before, $after));
    }

    // Through the API every change also writes the activity log: its area changes too,
    // and an unrelated area (here the staff screens') stays as it was.
    public function test_a_change_through_the_api_also_moves_the_activity_log(): void
    {
        Sanctum::actingAs($this->owner);
        $before = $this->getJson('/api/business/live/pulse')->json();

        $this->postJson('/api/business/client', ['first_name' => 'Mia', 'last_name' => 'Reyes', 'phone_number' => '09171234567', 'gender' => 'Female'])->assertSuccessful();

        $after = $this->getJson('/api/business/live/pulse')->json();
        $this->assertNotSame($before['token'], $after['token']);
        $this->assertNotSame($before['topics']['clients'], $after['topics']['clients']);
        $this->assertNotSame($before['topics']['audit'], $after['topics']['audit']);
        $this->assertSame($before['topics']['setup'], $after['topics']['setup']);
        $this->assertSame($before['topics']['billing'], $after['topics']['billing']);
    }

    // The bell: a notification for the owner (or a staff login) moves only `notifications`;
    // a client's belongs to no business and moves nothing.
    public function test_a_notification_moves_the_bells_area_and_a_clients_moves_nothing(): void
    {
        $before = $this->pulse();
        Notification::create(['user_id' => $this->owner->id, 'title' => 'New booking', 'message' => 'Mia booked', 'type' => 'Appointment']);
        $after = $this->pulse();
        $this->assertSame(['notifications'], $this->changed($before, $after));

        $client = User::factory()->create(['role' => 'client']);
        Notification::create(['user_id' => $client->id, 'title' => 'Booking confirmed', 'message' => 'See you', 'type' => 'Appointment']);
        $this->assertSame($after, $this->pulse());
    }

    // Another business's change is not this one's.
    public function test_areas_are_per_business(): void
    {
        $other = SpaBusiness::factory()->create(['owner_id' => User::factory()->create(['role' => 'business_owner'])->id]);
        $before = $this->pulse();

        Client::factory()->create(['spa_business_id' => $other->id]);

        $this->assertSame($before, $this->pulse());
    }
}

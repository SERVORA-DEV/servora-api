<?php

namespace Tests\Feature;

use App\Models\OwnerIdentityVerification;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\StaffScheduleChangeRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Staff Policies → "Owner approval for schedule changes": a manager's change
// to a staff member's weekly schedule waits for the owner; the owner's own
// change applies at once (StaffScheduleService).
//
// Needs PostgreSQL (see phpunit.xml):
//   DB_PASSWORD=... php artisan test --filter=ScheduleChangeApprovalTest
class ScheduleChangeApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private SpaBusiness $business;
    private SpaBranch $branch;
    private Staff $therapist;

    private const WEEK = ['days' => [
        ['day_of_week' => 'Monday', 'start_time' => '09:00', 'end_time' => '18:00'],
        ['day_of_week' => 'Sunday', 'is_day_off' => true],
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        OwnerIdentityVerification::create(['user_id' => $this->owner->id, 'status' => 'Verified']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);
        $this->therapist = Staff::factory()->create(['spa_branch_id' => $this->branch->id]);

        // The owner's dashboard routes need a live plan.
        $plan = SubscriptionPlan::create([
            'category' => 'Basic', 'name' => 'Basic', 'monthly_price' => 500, 'billing_cycle' => 'Monthly',
            'max_branches' => 5, 'max_user_accounts' => 10, 'is_active' => true,
        ]);
        Subscription::create([
            'spa_business_id' => $this->business->id, 'subscription_plan_id' => $plan->id, 'billing_cycle' => 'Monthly',
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(), 'status' => 'Active',
        ]);

        // A manager account, made the way the owner makes one.
        $managerStaff = Staff::factory()->create(['spa_branch_id' => $this->branch->id, 'role' => 'manager']);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/business/account', [
            'staff_uuid' => $managerStaff->uuid,
            'username' => 'branchmanager',
            'email' => 'manager@lotus.test',
            'password' => 'secret-password',
        ])->assertSuccessful();
        $this->manager = User::where('username', 'branchmanager')->firstOrFail();
    }

    private function requireApproval(bool $on): void
    {
        Sanctum::actingAs($this->owner);
        $this->patchJson('/api/business/settings/staff-policy', ['require_manager_approval' => $on])->assertOk();
    }

    private function saveSchedule(User $as)
    {
        Sanctum::actingAs($as);

        return $this->patchJson("/api/business/staff/{$this->therapist->uuid}/schedule", self::WEEK);
    }

    public function test_approval_is_off_until_the_owner_switches_it_on(): void
    {
        $this->saveSchedule($this->manager)->assertOk()->assertJsonPath('pending', false);

        $this->assertSame(2, StaffSchedule::where('staff_id', $this->therapist->id)->count());
        $this->assertSame(0, StaffScheduleChangeRequest::count());
    }

    public function test_a_managers_change_waits_for_the_owner_when_approval_is_on(): void
    {
        $this->requireApproval(true);

        $this->saveSchedule($this->manager)
            ->assertStatus(202)
            ->assertJsonPath('pending', true)
            ->assertJsonPath('pending_request.requested_by_me', true);

        // Nothing changed yet.
        $this->assertSame(0, StaffSchedule::where('staff_id', $this->therapist->id)->count());
        $this->assertSame(1, StaffScheduleChangeRequest::where('status', 'pending')->count());

        // The owner is told, and sees it in the list.
        $this->assertDatabaseHas('notifications', ['user_id' => $this->owner->id, 'title' => 'Schedule Change Needs Approval']);
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/business/schedule-requests')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_owner_approving_applies_the_proposed_week(): void
    {
        $this->requireApproval(true);
        $uuid = $this->saveSchedule($this->manager)->json('pending_request.uuid');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/business/schedule-requests/{$uuid}/approve")->assertOk();

        $this->assertSame(2, StaffSchedule::where('staff_id', $this->therapist->id)->count());
        $this->assertSame('approved', StaffScheduleChangeRequest::where('uuid', $uuid)->value('status'));
        $this->assertDatabaseHas('notifications', ['user_id' => $this->manager->id, 'title' => 'Schedule Change Approved']);
    }

    public function test_the_owner_rejecting_leaves_the_schedule_alone(): void
    {
        $this->requireApproval(true);
        $uuid = $this->saveSchedule($this->manager)->json('pending_request.uuid');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/business/schedule-requests/{$uuid}/reject", ['reason' => 'Needed on Sundays.'])->assertOk();

        $this->assertSame(0, StaffSchedule::where('staff_id', $this->therapist->id)->count());
        $this->assertSame('rejected', StaffScheduleChangeRequest::where('uuid', $uuid)->value('status'));
    }

    public function test_a_manager_cannot_approve_but_can_withdraw_their_own_request(): void
    {
        $this->requireApproval(true);
        $uuid = $this->saveSchedule($this->manager)->json('pending_request.uuid');

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/business/schedule-requests/{$uuid}/approve")->assertForbidden();
        $this->deleteJson("/api/business/schedule-requests/{$uuid}")->assertOk();

        $this->assertSame('withdrawn', StaffScheduleChangeRequest::where('uuid', $uuid)->value('status'));
    }

    public function test_the_owners_own_change_applies_at_once_and_overtakes_a_waiting_request(): void
    {
        $this->requireApproval(true);
        $uuid = $this->saveSchedule($this->manager)->json('pending_request.uuid');

        $this->saveSchedule($this->owner)->assertOk()->assertJsonPath('pending', false);

        $this->assertSame(2, StaffSchedule::where('staff_id', $this->therapist->id)->count());
        $this->assertSame('superseded', StaffScheduleChangeRequest::where('uuid', $uuid)->value('status'));
    }

    public function test_a_second_request_replaces_the_first(): void
    {
        $this->requireApproval(true);
        $first = $this->saveSchedule($this->manager)->json('pending_request.uuid');
        $second = $this->saveSchedule($this->manager)->json('pending_request.uuid');

        $this->assertNotSame($first, $second);
        $this->assertSame('superseded', StaffScheduleChangeRequest::where('uuid', $first)->value('status'));
        $this->assertSame(1, StaffScheduleChangeRequest::where('status', 'pending')->count());
    }
}

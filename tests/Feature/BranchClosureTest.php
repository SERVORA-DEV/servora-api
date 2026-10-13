<?php

namespace Tests\Feature;

use App\Models\OwnerIdentityVerification;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Service\Business\AppointmentAvailabilityService;
use App\Service\Client\BookingSlotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Temporary closures of a branch (a holiday, repairs): the owner or the
// branch's manager closes it for a window, nothing can be booked inside that
// window, and it reopens by itself (BranchClosureService, BranchClosures).
//
// Needs PostgreSQL (see phpunit.xml):
//   DB_PASSWORD=... php artisan test --filter=BranchClosureTest
class BranchClosureTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private SpaBranch $branch;
    private SpaBranch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        OwnerIdentityVerification::create(['user_id' => $this->owner->id, 'status' => 'Verified']);
        $business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $business->id]);
        $this->otherBranch = SpaBranch::factory()->create(['spa_business_id' => $business->id]);

        // The dashboard routes need a live plan.
        $plan = SubscriptionPlan::create([
            'category' => 'Basic', 'name' => 'Basic', 'monthly_price' => 500, 'billing_cycle' => 'Monthly',
            'max_branches' => 5, 'max_user_accounts' => 10, 'is_active' => true,
        ]);
        Subscription::create([
            'spa_business_id' => $business->id, 'subscription_plan_id' => $plan->id, 'billing_cycle' => 'Monthly',
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

    private function day(int $plus): string
    {
        return Carbon::now()->addDays($plus)->toDateString();
    }

    private function close(User $as, SpaBranch $branch, array $body)
    {
        Sanctum::actingAs($as);

        return $this->postJson("/api/business/branch/{$branch->uuid}/closures", $body + ['reason' => 'Holiday']);
    }

    public function test_a_manager_closes_their_own_branch_and_the_owner_is_told(): void
    {
        $this->close($this->manager, $this->branch, ['starts_at' => $this->day(1) . ' 13:00', 'ends_at' => $this->day(1) . ' 17:00'])
            ->assertCreated()
            ->assertJsonPath('data.all_day', false)
            ->assertJsonPath('data.active_now', false);

        $this->assertDatabaseHas('notifications', ['user_id' => $this->owner->id, 'title' => 'Branch Closing']);
        $this->getJson("/api/business/branch/{$this->branch->uuid}/closures")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_manager_cannot_close_another_branch_but_the_owner_can(): void
    {
        $window = ['starts_at' => $this->day(1) . ' 13:00', 'ends_at' => $this->day(1) . ' 17:00'];

        $this->close($this->manager, $this->otherBranch, $window)->assertNotFound();
        $this->close($this->owner, $this->otherBranch, $window)->assertCreated();
    }

    public function test_an_all_day_closure_covers_whole_days(): void
    {
        $this->close($this->owner, $this->branch, ['starts_at' => $this->day(3) . ' 00:00', 'ends_at' => $this->day(4) . ' 00:00', 'all_day' => true])
            ->assertCreated()
            ->assertJsonPath('data.starts_at', $this->day(3) . ' 00:00')
            ->assertJsonPath('data.ends_at', $this->day(5) . ' 00:00')
            ->assertJsonPath('data.last_day', $this->day(4));
    }

    public function test_windows_that_make_no_sense_are_refused(): void
    {
        $this->close($this->owner, $this->branch, ['starts_at' => $this->day(1) . ' 13:00', 'ends_at' => $this->day(1) . ' 17:00'])->assertCreated();

        // Over another closure, already passed, backwards, too long.
        $this->close($this->owner, $this->branch, ['starts_at' => $this->day(1) . ' 16:00', 'ends_at' => $this->day(1) . ' 19:00'])->assertStatus(422);
        $this->close($this->owner, $this->branch, ['starts_at' => $this->day(-3) . ' 10:00', 'ends_at' => $this->day(-2) . ' 10:00'])->assertStatus(422);
        $this->close($this->owner, $this->branch, ['starts_at' => $this->day(9) . ' 10:00', 'ends_at' => $this->day(9) . ' 09:00'])->assertStatus(422);
        $this->close($this->owner, $this->branch, ['starts_at' => $this->day(9) . ' 10:00', 'ends_at' => $this->day(120) . ' 10:00'])->assertStatus(422);
    }

    public function test_nothing_can_be_booked_inside_a_closure(): void
    {
        $this->close($this->owner, $this->branch, ['starts_at' => $this->day(1) . ' 13:00', 'ends_at' => $this->day(1) . ' 17:00'])->assertCreated();
        $availability = app(AppointmentAvailabilityService::class);

        $this->assertTrue($availability->branchIsOpen($this->branch->id, $this->day(1), '11:00', 60)['ok']);
        $this->assertFalse($availability->branchIsOpen($this->branch->id, $this->day(1), '14:00', 60)['ok']);
        // Starts before it, runs into it.
        $this->assertFalse($availability->branchIsOpen($this->branch->id, $this->day(1), '12:30', 60)['ok']);
        // Reopens at 5:00 PM.
        $this->assertTrue($availability->branchIsOpen($this->branch->id, $this->day(1), '17:00', 60)['ok']);

        // The client's time picker offers nothing inside it either.
        $times = array_column(app(BookingSlotService::class)->slots($this->branch, $this->day(1), 60)['slots'], 'time');
        $this->assertNotContains('13:00', $times);
        $this->assertNotContains('16:30', $times);
        $this->assertNotContains('12:30', $times);
    }

    public function test_a_day_closed_all_day_says_why_in_the_time_picker(): void
    {
        $this->close($this->owner, $this->branch, ['starts_at' => $this->day(2) . ' 00:00', 'ends_at' => $this->day(2) . ' 00:00', 'all_day' => true])->assertCreated();

        $day = app(BookingSlotService::class)->slots($this->branch, $this->day(2), 60);

        $this->assertSame([], $day['slots']);
        $this->assertStringContainsString('Holiday', (string) $day['closed_reason']);
    }

    public function test_a_running_closure_is_ended_and_a_planned_one_is_removed(): void
    {
        $running = $this->close($this->owner, $this->branch, [
            'starts_at' => Carbon::now()->subHour()->format('Y-m-d H:i'), 'ends_at' => Carbon::now()->addHours(3)->format('Y-m-d H:i'),
        ])->assertCreated()->assertJsonPath('data.active_now', true)->json('data.uuid');
        $planned = $this->close($this->owner, $this->branch, ['starts_at' => $this->day(5) . ' 00:00', 'ends_at' => $this->day(5) . ' 00:00', 'all_day' => true])
            ->assertCreated()->json('data.uuid');

        $this->deleteJson("/api/business/branch/{$this->branch->uuid}/closures/{$running}")->assertOk();
        $this->deleteJson("/api/business/branch/{$this->branch->uuid}/closures/{$planned}")->assertOk();

        // The ended one is kept as a record; the planned one is gone. Neither is listed.
        $this->assertDatabaseHas('branch_closures', ['uuid' => $running]);
        $this->assertDatabaseMissing('branch_closures', ['uuid' => $planned]);
        $this->getJson("/api/business/branch/{$this->branch->uuid}/closures")->assertOk()->assertJsonCount(0, 'data');
    }
}

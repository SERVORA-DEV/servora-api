<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\OwnerIdentityVerification;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// A change at a branch — the owner's or a manager's — reaches that branch's
// front desk accounts, by the owner's switches alone (Business Settings >
// Notifications > "Front desk is told about", all on until switched off) —
// ManagerChangeNotifier.
//
// Needs PostgreSQL (see phpunit.xml):
//   DB_PASSWORD=... php artisan test --filter=FrontDeskChangeNotificationTest
class FrontDeskChangeNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $frontDesk;
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

        // A manager and a front desk account, made the way the owner makes them.
        $this->manager = $this->account('manager', 'branchmanager', 'manager@lotus.test');
        $this->frontDesk = $this->account('frontdesk', 'branchdesk', 'desk@lotus.test');
    }

    private function account(string $staffRole, string $username, string $email): User
    {
        $staff = Staff::factory()->create(['spa_branch_id' => $this->branch->id, 'role' => $staffRole]);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/business/account', [
            'staff_uuid' => $staff->uuid,
            'username' => $username,
            'email' => $email,
            'password' => 'secret-password',
        ])->assertSuccessful();

        return User::where('username', $username)->firstOrFail();
    }

    private function saveHours(User $as, ?SpaBranch $branch = null)
    {
        Sanctum::actingAs($as);
        $branch ??= $this->branch;

        return $this->putJson("/api/business/branch/{$branch->uuid}/schedule", [
            'days' => [['day_of_week' => 'Monday', 'opening_time' => '09:00', 'closing_time' => '20:00']],
        ])->assertOk();
    }

    private function switches(array $values): void
    {
        Sanctum::actingAs($this->owner);
        $this->patchJson('/api/business/settings/notifications', $values)->assertOk();
    }

    private function notices(User $user, ?string $title = null): int
    {
        return Notification::where('user_id', $user->id)->when($title, fn ($q) => $q->where('title', $title))->count();
    }

    public function test_the_front_desk_is_told_when_the_owner_changes_their_branch(): void
    {
        $this->saveHours($this->owner);

        $this->assertSame(1, $this->notices($this->frontDesk, 'Opening Hours Changed'));
        // The manager still is, and the owner is not told about their own change.
        $this->assertSame(1, $this->notices($this->manager, 'Opening Hours Changed'));
        $this->assertSame(0, $this->notices($this->owner));
    }

    // The front desk hears about a manager's change whether or not the owner
    // asked to hear about it themselves.
    public function test_the_front_desk_is_told_when_the_manager_changes_their_branch(): void
    {
        $this->saveHours($this->manager);

        $this->assertSame(1, $this->notices($this->frontDesk, 'Opening Hours Changed'));
        $this->assertSame(0, $this->notices($this->owner));
        $this->assertSame(0, $this->notices($this->manager));
    }

    public function test_the_owner_switches_a_kind_off_for_the_front_desk(): void
    {
        $this->switches(['frontdesk_hours' => false]);

        $this->saveHours($this->owner);
        $this->saveHours($this->manager);
        $this->assertSame(0, $this->notices($this->frontDesk));
        // The manager's notification is the manager's own business.
        $this->assertSame(1, $this->notices($this->manager, 'Opening Hours Changed'));

        // The other kinds still arrive.
        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/business/branch/{$this->branch->uuid}/booking-policy", ['overrides' => ['allow_reschedule' => false]])->assertOk();
        $this->assertSame(1, $this->notices($this->frontDesk, 'Booking Rules Changed'));
    }

    public function test_one_save_sent_as_two_requests_is_one_notification(): void
    {
        $this->saveHours($this->owner);
        $this->saveHours($this->owner);

        $this->assertSame(1, $this->notices($this->frontDesk));
    }

    public function test_another_branchs_changes_are_not_theirs_to_hear(): void
    {
        $this->saveHours($this->owner, $this->otherBranch);

        $this->assertSame(0, $this->notices($this->frontDesk));
    }

    public function test_the_business_master_switch_turns_them_off(): void
    {
        $this->switches(['in_app_channel' => false]);

        $this->saveHours($this->owner);

        $this->assertSame(0, $this->notices($this->frontDesk));
    }

    public function test_only_the_owner_sets_the_switches(): void
    {
        Sanctum::actingAs($this->frontDesk);
        $this->patchJson('/api/business/settings/notifications', ['frontdesk_hours' => false])->assertForbidden();

        Sanctum::actingAs($this->manager);
        $this->patchJson('/api/business/settings/notifications', ['frontdesk_hours' => false])->assertForbidden();
    }
}

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

// The owner's changes at a branch reach that branch's manager, by the
// manager's own switches (Branch Settings > Notifications, all on until
// switched off) — ManagerChangeNotifier, ManagerNotificationPrefs.
//
// Needs PostgreSQL (see phpunit.xml):
//   DB_PASSWORD=... php artisan test --filter=OwnerChangeNotificationTest
class OwnerChangeNotificationTest extends TestCase
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

    private function saveHours(User $as, SpaBranch $branch)
    {
        Sanctum::actingAs($as);

        return $this->putJson("/api/business/branch/{$branch->uuid}/schedule", [
            'days' => [['day_of_week' => 'Monday', 'opening_time' => '09:00', 'closing_time' => '20:00']],
        ])->assertOk();
    }

    private function managerNotices(?string $title = null): int
    {
        return Notification::where('user_id', $this->manager->id)->when($title, fn ($q) => $q->where('title', $title))->count();
    }

    public function test_the_manager_is_told_when_the_owner_changes_their_branch(): void
    {
        $this->saveHours($this->owner, $this->branch);

        $this->assertSame(1, $this->managerNotices('Opening Hours Changed'));
        // The owner is not told about their own change.
        $this->assertSame(0, Notification::where('user_id', $this->owner->id)->count());
    }

    // One click on a screen is often several requests; the manager gets one
    // notification for them, not one each.
    public function test_one_save_sent_as_two_requests_is_one_notification(): void
    {
        $this->saveHours($this->owner, $this->branch);
        $this->saveHours($this->owner, $this->branch);

        $this->assertSame(1, $this->managerNotices());
    }

    public function test_a_different_change_straight_after_joins_the_same_notification(): void
    {
        $this->saveHours($this->owner, $this->branch);
        $this->patchJson("/api/business/branch/{$this->branch->uuid}/booking-policy", ['overrides' => ['allow_reschedule' => false]])->assertOk();

        $this->assertSame(1, $this->managerNotices());
        $message = Notification::where('user_id', $this->manager->id)->value('message');
        $this->assertStringContainsString('changed the opening hours', $message);
        $this->assertStringContainsString('Also changed the booking rules', $message);
    }

    public function test_a_change_after_the_last_one_was_read_is_a_new_notification(): void
    {
        $this->saveHours($this->owner, $this->branch);
        Notification::where('user_id', $this->manager->id)->update(['is_read' => true]);

        $this->saveHours($this->owner, $this->branch);

        $this->assertSame(2, $this->managerNotices());
    }

    public function test_another_branchs_changes_are_not_theirs_to_hear(): void
    {
        $this->saveHours($this->owner, $this->otherBranch);

        $this->assertSame(0, $this->managerNotices());
    }

    // "Managers are always told about": hours start locked on.
    private function unlock(string ...$kinds): void
    {
        Sanctum::actingAs($this->owner);
        $this->patchJson('/api/business/settings/notifications', array_fill_keys(array_map(fn ($k) => "lock_owner_{$k}", $kinds), false))->assertOk();
    }

    public function test_a_kind_the_owner_locked_cannot_be_switched_off(): void
    {
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/business/notification-preferences')->assertOk()
            ->assertJsonPath('locked', ['on_owner_hours', 'on_owner_booking_rules', 'on_owner_catalog']);

        // The attempt is accepted but ignored for the locked kind.
        $this->patchJson('/api/business/notification-preferences', ['on_owner_hours' => false, 'on_owner_rooms' => false])
            ->assertOk()
            ->assertJsonPath('data.on_owner_hours', true)
            ->assertJsonPath('data.on_owner_rooms', false);

        $this->saveHours($this->owner, $this->branch);
        $this->assertSame(1, $this->managerNotices('Opening Hours Changed'));
    }

    public function test_locking_a_kind_overrides_a_managers_own_switch(): void
    {
        Sanctum::actingAs($this->manager);
        $this->patchJson('/api/business/notification-preferences', ['on_owner_listing' => false])->assertOk();

        Sanctum::actingAs($this->owner);
        $this->patchJson('/api/business/settings/notifications', ['lock_owner_listing' => true])->assertOk();
        $this->patchJson("/api/business/branch/{$this->branch->uuid}/marketplace", ['promo_text' => '10% off this week'])->assertOk();

        $this->assertSame(1, $this->managerNotices('Listing Changed'));
    }

    public function test_a_manager_switches_a_kind_off(): void
    {
        $this->unlock('hours');
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/business/notification-preferences')->assertOk()->assertJsonPath('data.on_owner_hours', true);
        $this->patchJson('/api/business/notification-preferences', ['on_owner_hours' => false])
            ->assertOk()
            ->assertJsonPath('data.on_owner_hours', false)
            ->assertJsonPath('data.on_owner_booking_rules', true);

        $this->saveHours($this->owner, $this->branch);
        $this->assertSame(0, $this->managerNotices());

        // The other kinds still arrive.
        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/business/branch/{$this->branch->uuid}/booking-policy", ['overrides' => ['allow_reschedule' => false]])->assertOk();
        $this->assertSame(1, $this->managerNotices('Booking Rules Changed'));
    }

    public function test_the_switches_are_the_managers_own(): void
    {
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/business/notification-preferences')->assertForbidden();
    }

    public function test_the_business_master_switch_turns_them_off(): void
    {
        Sanctum::actingAs($this->owner);
        $this->patchJson('/api/business/settings/notifications', ['in_app_channel' => false])->assertOk();

        $this->saveHours($this->owner, $this->branch);

        $this->assertSame(0, $this->managerNotices());
    }
}

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

// Settings > Notifications > "When a manager changes something": the owner
// hears about a kind of change only after switching it on, and never about
// their own changes (ManagerChangeNotifier).
//
// Needs PostgreSQL (see phpunit.xml):
//   DB_PASSWORD=... php artisan test --filter=ManagerChangeNotificationTest
class ManagerChangeNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private SpaBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        OwnerIdentityVerification::create(['user_id' => $this->owner->id, 'status' => 'Verified']);
        $business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $business->id]);

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

    private function switchOn(array $keys): void
    {
        Sanctum::actingAs($this->owner);
        $this->patchJson('/api/business/settings/notifications', array_fill_keys($keys, true))->assertOk();
    }

    private function saveHours(User $as)
    {
        Sanctum::actingAs($as);

        return $this->putJson("/api/business/branch/{$this->branch->uuid}/schedule", [
            'days' => [['day_of_week' => 'Monday', 'opening_time' => '09:00', 'closing_time' => '20:00']],
        ])->assertOk();
    }

    private function ownerNotices(): int
    {
        return Notification::where('user_id', $this->owner->id)->count();
    }

    public function test_nothing_is_sent_until_the_owner_switches_it_on(): void
    {
        $this->saveHours($this->manager);

        $this->assertSame(0, $this->ownerNotices());
    }

    public function test_the_owner_is_told_about_a_kind_they_switched_on(): void
    {
        $this->switchOn(['on_manager_hours']);
        $this->saveHours($this->manager);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->owner->id,
            'title' => 'Opening Hours Changed',
            'reference_uuid' => $this->branch->uuid,
        ]);
        // The manager who made the change is not told about it.
        $this->assertSame(0, Notification::where('user_id', $this->manager->id)->count());
    }

    public function test_other_kinds_stay_quiet(): void
    {
        $this->switchOn(['on_manager_hours']);

        Sanctum::actingAs($this->manager);
        $this->patchJson("/api/business/branch/{$this->branch->uuid}/marketplace", ['promo_text' => '10% off this week'])->assertOk();

        $this->assertSame(0, $this->ownerNotices());
    }

    public function test_the_owners_own_changes_never_notify(): void
    {
        $this->switchOn(['on_manager_hours']);
        $this->saveHours($this->owner);

        $this->assertSame(0, $this->ownerNotices());
    }

    public function test_a_listing_save_that_changes_nothing_says_nothing(): void
    {
        $this->switchOn(['on_manager_listing']);

        Sanctum::actingAs($this->manager);
        $this->patchJson("/api/business/branch/{$this->branch->uuid}/marketplace", ['promo_text' => '10% off this week'])->assertOk();
        $this->patchJson("/api/business/branch/{$this->branch->uuid}/marketplace", ['promo_text' => '10% off this week'])->assertOk();

        $this->assertSame(1, $this->ownerNotices());
        $this->assertDatabaseHas('notifications', ['user_id' => $this->owner->id, 'title' => 'Listing Changed']);
    }

    public function test_the_master_switch_turns_them_off(): void
    {
        $this->switchOn(['on_manager_hours']);
        Sanctum::actingAs($this->owner);
        $this->patchJson('/api/business/settings/notifications', ['in_app_channel' => false])->assertOk();

        $this->saveHours($this->manager);

        $this->assertSame(0, $this->ownerNotices());
    }
}

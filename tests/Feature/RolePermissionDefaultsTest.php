<?php

namespace Tests\Feature;

use App\Models\OwnerIdentityVerification;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Business Settings → Staff Policies sets the starting permissions for new
// Manager / Front Officer accounts, and EnsurePermission enforces each
// account's own flags (not just its role's bundle).
//
// Needs PostgreSQL (see phpunit.xml):
//   DB_PASSWORD=... php artisan test --filter=RolePermissionDefaultsTest
class RolePermissionDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private SpaBusiness $business;
    private SpaBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        OwnerIdentityVerification::create(['user_id' => $this->owner->id, 'status' => 'Verified']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);
    }

    private function createAccount(string $staffRole): User
    {
        $staff = Staff::factory()->create(['spa_branch_id' => $this->branch->id, 'role' => $staffRole]);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/business/account', [
            'staff_uuid' => $staff->uuid,
            'username' => 'acct'.$staff->id,
            'email' => "acct{$staff->id}@lotus.test",
            'password' => 'secret-password',
        ])->assertSuccessful();

        return User::where('username', 'acct'.$staff->id)->firstOrFail();
    }

    public function test_new_accounts_start_with_the_owners_defaults_for_their_role(): void
    {
        Sanctum::actingAs($this->owner);
        $this->patchJson('/api/business/settings/staff-policy', [
            'role_permissions' => [
                'manager' => ['staff_create' => false],
                'front_officer' => ['payment_create' => false],
            ],
        ])->assertOk();

        $manager = $this->createAccount('manager');
        $frontOfficer = $this->createAccount('frontdesk');

        $this->assertFalse($manager->permission->staff_create);
        $this->assertTrue($manager->permission->staff_view);

        $this->assertFalse($frontOfficer->permission->payment_create);
        $this->assertTrue($frontOfficer->permission->attendance_checkin);
    }

    public function test_changing_defaults_leaves_existing_accounts_alone(): void
    {
        $manager = $this->createAccount('manager');

        $this->patchJson('/api/business/settings/staff-policy', [
            'role_permissions' => ['manager' => ['staff_create' => false]],
        ])->assertOk();

        $this->assertTrue($manager->fresh()->permission->staff_create);
    }

    public function test_an_accounts_own_flag_is_enforced(): void
    {
        $manager = $this->createAccount('manager');
        $therapist = Staff::factory()->create(['spa_branch_id' => $this->branch->id]);
        $route = "/api/business/staff/{$therapist->uuid}/services";

        Sanctum::actingAs($manager->fresh());
        $this->getJson($route)->assertOk();

        UserPermission::where('user_id', $manager->id)->update(['staff_view' => false]);

        Sanctum::actingAs($manager->fresh());
        $this->getJson($route)->assertForbidden();
    }

    public function test_front_officer_check_in_follows_its_flag(): void
    {
        $frontOfficer = $this->createAccount('frontdesk');

        // Allowed → gets past the permission gate to validation.
        Sanctum::actingAs($frontOfficer->fresh());
        $this->postJson('/api/business/frontoffice/attendance/check-in', [])->assertStatus(422);

        UserPermission::where('user_id', $frontOfficer->id)->update(['attendance_checkin' => false]);

        Sanctum::actingAs($frontOfficer->fresh());
        $this->postJson('/api/business/frontoffice/attendance/check-in', [])->assertForbidden();
    }
}

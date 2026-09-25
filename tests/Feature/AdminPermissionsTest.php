<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// System administrator permissions (Settings → Administrators) are enforced
// by EnsurePermission on every /system route, and AdminUsersService guards
// who may change them.
//
//   DB_PASSWORD=... php artisan test --filter=AdminPermissionsTest
class AdminPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $grant = null, string $status = 'Active'): User
    {
        $user = User::factory()->create([
            'role' => 'system_administrator',
            'account_status' => $status,
            'email_verified_at' => now(),
        ]);

        $flags = array_fill_keys(AdminPermissions::keys(), $grant === null);
        foreach ($grant ?? [] as $key) {
            $flags[$key] = true;
        }
        UserPermission::create(['user_id' => $user->id, ...$flags]);

        return $user->fresh('permission');
    }

    public function test_a_limited_admin_is_refused_outside_their_permissions(): void
    {
        Sanctum::actingAs($this->admin(['spa_business_view', 'branch_view']));

        $this->getJson('/api/system/businesses')->assertOk();
        $this->getJson('/api/system/branches')->assertOk();

        $this->getJson('/api/system/subscription-plans')->assertForbidden();
        $this->getJson('/api/system/service-templates')->assertForbidden();
        $this->getJson('/api/system/admin/user-management')->assertForbidden();
        $this->getJson('/api/system/audit-logs')->assertForbidden();
        $this->patchJson('/api/system/settings', [])->assertForbidden();
    }

    public function test_the_me_payload_carries_the_admins_permissions(): void
    {
        Sanctum::actingAs($this->admin(['dashboard_view']));

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.permissions.dashboard_view', true)
            ->assertJsonPath('data.permissions.admin_view', false);
    }

    public function test_an_admin_without_a_permission_row_keeps_the_full_bundle(): void
    {
        $user = User::factory()->create(['role' => 'system_administrator', 'email_verified_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/system/service-templates')->assertOk();
    }

    public function test_the_list_only_contains_administrators(): void
    {
        Sanctum::actingAs($this->admin());
        User::factory()->create(['role' => 'business_owner']);

        $response = $this->getJson('/api/system/admin/user-management')->assertOk();
        $this->assertSame(['system_administrator'], collect($response->json('data'))->pluck('role')->unique()->values()->all());
    }

    public function test_the_admin_endpoint_cannot_edit_a_non_admin_account(): void
    {
        Sanctum::actingAs($this->admin());
        $owner = User::factory()->create(['role' => 'business_owner']);

        $this->patchJson("/api/system/admin/user-management/{$owner->uuid}", ['first_name' => 'X'])->assertNotFound();
    }

    public function test_changing_permissions_needs_permission_update(): void
    {
        $target = $this->admin();
        Sanctum::actingAs($this->admin(['admin_view', 'admin_update']));

        $this->patchJson("/api/system/admin/user-management/{$target->uuid}", ['audit_log_view' => false])
            ->assertForbidden();

        // Unchanged flags sent along with a name edit are not a permission change.
        $this->patchJson("/api/system/admin/user-management/{$target->uuid}", ['first_name' => 'Renamed', 'audit_log_view' => true])
            ->assertOk();
    }

    public function test_an_admin_cannot_change_their_own_permissions(): void
    {
        $me = $this->admin();
        $this->admin(); // someone else can still manage accounts
        Sanctum::actingAs($me);

        $this->patchJson("/api/system/admin/user-management/{$me->uuid}", ['audit_log_view' => false])
            ->assertStatus(422)->assertJsonValidationErrors('permissions');
    }

    public function test_permissions_are_saved_and_enforced(): void
    {
        $target = $this->admin();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/system/admin/user-management/{$target->uuid}", ['subscription_plan_view' => false])->assertOk();

        Sanctum::actingAs($target->fresh('permission'));
        $this->getJson('/api/system/subscription-plans')->assertForbidden();
    }

    public function test_the_last_account_manager_cannot_be_deactivated(): void
    {
        $onlyManager = $this->admin();
        Sanctum::actingAs($this->admin(['admin_view', 'admin_delete']));

        $this->deleteJson("/api/system/admin/user-management/{$onlyManager->uuid}")
            ->assertStatus(422)->assertJsonValidationErrors('permissions');

        // With a second manager around it's fine.
        $this->admin();
        $this->deleteJson("/api/system/admin/user-management/{$onlyManager->uuid}")->assertOk();
    }

    public function test_deactivate_keeps_the_account_but_blocks_sign_in(): void
    {
        $target = $this->admin();
        $target->forceFill(['password' => bcrypt('secret-pass')])->save();
        $target->createToken('web');

        Sanctum::actingAs($this->admin());
        $this->deleteJson("/api/system/admin/user-management/{$target->uuid}")
            ->assertOk()
            ->assertJsonPath('data.account_status', 'Inactive');

        $this->assertDatabaseHas('users', ['id' => $target->id, 'account_status' => 'Inactive']);
        $this->assertSame(0, $target->tokens()->count());

        $this->postJson('/api/auth/login', ['email' => $target->email, 'password' => 'secret-pass'])
            ->assertForbidden();
    }

    public function test_an_admin_cannot_deactivate_themselves(): void
    {
        $me = $this->admin();
        Sanctum::actingAs($me);

        $this->deleteJson("/api/system/admin/user-management/{$me->uuid}")->assertStatus(422);
    }
}

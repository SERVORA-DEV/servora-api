<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserPermission;
use App\Repository\AuditLogRepository;
use App\Service\NotificationService;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ServiceTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class PrimaryAdministratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Isolated schema only: never migrate or write the configured database.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        config(['admin_access.allowed_ips' => []]);
        DB::purge('sqlite');
        (require database_path('migrations/2026_07_17_063713_create_users_table.php'))->up();
        Schema::table('users', fn (Blueprint $t) => $t->string('audience')->default('web'));
        Schema::create('staff', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->nullable(); $t->softDeletes();
        });
        Schema::create('user_permissions', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->unique();
            foreach (AdminPermissions::keys() as $key) $t->boolean($key)->default(false);
            $t->timestamps();
        });
        (require database_path('migrations/2026_07_17_063802_create_personal_access_tokens_table.php'))->up();
        $this->migration()->up();

        $audit = Mockery::mock(AuditLogRepository::class)->makePartial();
        $audit->shouldReceive('recordAdminAction')->andReturn(new AuditLog());
        app()->instance(AuditLogRepository::class, $audit);
        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('administratorCreated')->andReturnNull();
        app()->instance(NotificationService::class, $notifications);
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_11_19_090000_protect_primary_administrator.php');
    }

    private function admin(bool $primary = false, bool $fullAccess = true): User
    {
        $user = User::create([
            'role' => 'system_administrator', 'first_name' => 'Original', 'last_name' => 'Admin',
            'email' => fake()->unique()->safeEmail(), 'password' => 'Test-password1',
            'account_status' => 'Active', 'email_verified_at' => now(),
        ]);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        if ($primary) $user->forceFill(['is_primary_admin' => true])->save();
        UserPermission::create(['user_id' => $user->id, ...array_fill_keys(AdminPermissions::keys(), $fullAccess)]);

        return $user->fresh('permission');
    }

    private function endpoint(User $user): string
    {
        return "/api/system/admin/user-management/{$user->uuid}";
    }

    public function test_migration_marks_only_the_original_admin_regardless_of_email_or_creation_date_edits(): void
    {
        $this->migration()->down();
        User::create(['role' => 'business_owner', 'email' => 'owner@example.test', 'password' => 'Test-password1']);
        $original = $this->admin();
        $original->update(['email' => 'renamed@example.test', 'created_at' => now()->addDay()]);
        $later = $this->admin();
        $later->update(['email' => 'admin@servora.com', 'created_at' => now()->subYear()]);

        $this->migration()->up();

        $this->assertTrue($original->fresh()->isPrimaryAdmin());
        $this->assertFalse($later->fresh()->isPrimaryAdmin());
        $this->assertSame(1, User::where('is_primary_admin', true)->count());
    }

    public function test_fresh_seed_and_reseeding_keep_the_same_primary_after_an_email_change(): void
    {
        $seeder = Mockery::mock(DatabaseSeeder::class)->makePartial();
        $seeder->shouldReceive('call')->with(ServiceTemplateSeeder::class)->twice();
        $seeder->run();
        $primary = User::where('is_primary_admin', true)->firstOrFail();
        $primary->update(['email' => 'changed@example.test']);
        $this->admin();
        $seeder->run();

        $this->assertSame(2, User::count());
        $this->assertSame($primary->id, User::where('is_primary_admin', true)->sole()->id);
        $this->assertSame('changed@example.test', $primary->fresh()->email);
    }

    public function test_primary_keeps_full_access_with_a_restricted_permission_row_in_auth_and_management(): void
    {
        $primary = $this->admin(primary: true, fullAccess: false);
        Sanctum::actingAs($primary);
        $me = $this->getJson('/api/auth/me')->assertOk();
        foreach (AdminPermissions::keys() as $key) $me->assertJsonPath("data.permissions.{$key}", true);
        $this->getJson('/api/system/admin/user-management')->assertOk();
        $detail = $this->getJson($this->endpoint($primary))->assertOk()->assertJsonPath('data.is_primary_admin', true);
        foreach (AdminPermissions::keys() as $key) $detail->assertJsonPath("data.permission.{$key}", true);
    }

    public function test_other_admins_cannot_edit_primary_details_permissions_password_or_status(): void
    {
        $primary = $this->admin(primary: true);
        Sanctum::actingAs($this->admin());
        $before = $primary->getAttributes();
        foreach ([['first_name' => 'Changed'], ['audit_log_view' => false], ['password' => 'Changed-password1'],
            ['email' => 'changed@example.test'], ['account_status' => 'Suspended'], ['account_status' => 'Inactive']] as $payload) {
            $this->patchJson($this->endpoint($primary), $payload)->assertForbidden()->assertJsonFragment(['message' =>
                'The primary administrator is protected. Its full access is fixed, and this account cannot be edited, suspended, or deactivated here.']);
            $this->assertSame($before, $primary->fresh()->getAttributes());
        }
        $this->putJson($this->endpoint($primary), ['first_name' => 'Changed'])->assertForbidden();
        $this->assertTrue($primary->fresh('permission')->permission->audit_log_view);
    }

    public function test_primary_cannot_be_deactivated_even_with_another_full_admin_and_keeps_its_tokens(): void
    {
        $primary = $this->admin(primary: true);
        $primary->createToken('web');
        Sanctum::actingAs($this->admin());
        $this->deleteJson($this->endpoint($primary))->assertForbidden();
        $this->assertSame('Active', $primary->fresh()->account_status);
        $this->assertSame(1, $primary->tokens()->count());
    }

    public function test_primary_can_update_own_profile_but_not_own_access_or_status(): void
    {
        $primary = $this->admin(primary: true);
        Sanctum::actingAs($primary);
        $this->patchJson($this->endpoint($primary), ['first_name' => 'Updated', 'email' => 'updated@example.test'])
            ->assertOk()->assertJsonPath('data.is_primary_admin', true);
        $this->assertSame('Updated', $primary->fresh()->first_name);
        $this->patchJson($this->endpoint($primary), ['admin_update' => false])->assertForbidden();
        $this->patchJson($this->endpoint($primary), ['account_status' => 'Suspended'])->assertForbidden();
        $this->deleteJson($this->endpoint($primary))->assertForbidden();
    }

    public function test_other_admins_remain_editable_and_deactivatable(): void
    {
        $primary = $this->admin(primary: true);
        $target = $this->admin();
        Sanctum::actingAs($primary);
        $this->patchJson($this->endpoint($target), ['first_name' => 'Updated', 'audit_log_view' => false])
            ->assertOk()->assertJsonPath('data.is_primary_admin', false)->assertJsonPath('data.permission.audit_log_view', false);
        $this->patchJson($this->endpoint($target), ['account_status' => 'Suspended'])->assertOk();
        $this->patchJson($this->endpoint($target), ['account_status' => 'Active'])->assertOk();
        $this->deleteJson($this->endpoint($target))->assertOk()->assertJsonPath('data.account_status', 'Inactive');
    }

    public function test_a_normal_limited_admin_does_not_gain_primary_permissions(): void
    {
        Sanctum::actingAs($this->admin(fullAccess: false));
        $this->getJson('/api/system/admin/user-management')->assertForbidden();
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.permissions.admin_update', false);
    }

    public function test_forged_primary_flags_are_not_mass_assignable_or_accepted_by_management(): void
    {
        $primary = $this->admin(primary: true);
        $target = $this->admin();
        $target->fill(['is_primary_admin' => true])->save();
        $this->assertFalse($target->fresh()->isPrimaryAdmin());
        Sanctum::actingAs($primary);
        $this->patchJson($this->endpoint($target), ['first_name' => 'Updated', 'is_primary_admin' => true])->assertOk();
        $this->patchJson($this->endpoint($primary), ['is_primary_admin' => false])->assertOk();
        $this->assertTrue($primary->fresh()->isPrimaryAdmin());
        $this->assertFalse($target->fresh()->isPrimaryAdmin());
    }

    public function test_database_allows_only_one_primary_admin(): void
    {
        $this->admin(primary: true);
        $this->expectException(QueryException::class);
        $this->admin(primary: true);
    }
}

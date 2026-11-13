<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use App\Support\AdminPermissions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
            'admin_access.allowed_ips' => [], 'admin_access.require_two_factor' => false]);
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('uuid'); $t->string('role'); $t->string('audience');
            $t->string('first_name')->nullable(); $t->string('last_name')->nullable(); $t->timestamps();
        });
        Schema::create('user_permissions', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id');
            foreach (AdminPermissions::keys() as $key) $t->boolean($key)->default(false);
            $t->timestamps();
        });
        Schema::create('spa_businesses', function (Blueprint $t) {
            $t->id(); $t->string('uuid'); $t->foreignId('owner_id')->nullable(); $t->string('business_name');
            $t->string('verification_status'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('spa_branches', function (Blueprint $t) {
            $t->id(); $t->string('uuid'); $t->foreignId('spa_business_id'); $t->string('branch_name');
            $t->string('verification_status'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id(); $t->string('table_name'); $t->unsignedBigInteger('record_id'); $t->string('action'); $t->timestamp('created_at');
        });
    }

    private function signIn(string $role = 'system_administrator', bool $permission = true): void
    {
        $user = User::create(['role' => $role, 'first_name' => 'Test', 'last_name' => 'Owner']);
        UserPermission::create(['user_id' => $user->id, 'report_view' => $permission]);
        Sanctum::actingAs($user);
    }

    private function business(int $id, string $status = 'Verified', ?string $deleted = null): void
    {
        DB::table('spa_businesses')->insert(['id' => $id, 'uuid' => "business-$id", 'owner_id' => 1, 'business_name' => 'Same business name',
            'verification_status' => $status, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-11-10 00:00:00', 'deleted_at' => $deleted]);
    }

    private function branch(int $id, string $status = 'Verified', int $business = 1, ?string $deleted = null): void
    {
        DB::table('spa_branches')->insert(['id' => $id, 'uuid' => "branch-$id", 'spa_business_id' => $business, 'branch_name' => "Branch $id",
            'verification_status' => $status, 'created_at' => '2026-02-01 00:00:00', 'updated_at' => '2026-11-10 00:00:00', 'deleted_at' => $deleted]);
    }

    private function submission(string $table, int $id, string $date): void
    {
        DB::table('audit_logs')->insert(['table_name' => $table, 'record_id' => $id, 'action' => 'Submit', 'created_at' => $date]);
    }

    public function test_history_includes_all_review_states_and_uses_first_submission_not_last_edit(): void
    {
        $this->signIn(); $this->business(1);
        foreach (['Pending', 'Verified', 'Rejected', 'Suspended'] as $i => $status) $this->branch($i + 1, $status);
        $this->submission('spa_branches', 1, '2026-03-02 00:00:00');
        $this->submission('spa_branches', 1, '2026-10-01 00:00:00');
        $this->submission('spa_businesses', 1, '2026-04-01 00:00:00');
        $response = $this->getJson('/api/system/reports/registrations')->assertOk()->assertJsonPath('total', 5);
        $rows = collect($response->json('data'))->keyBy('uuid');
        $this->assertSame('2026-03-02T00:00:00', substr($rows['branch-1']['registered_at'], 0, 19));
        $this->assertSame('submitted', $rows['branch-1']['date_basis']);
        $this->assertSame('2026-04-01T00:00:00', substr($rows['business-1']['registered_at'], 0, 19));
        $this->assertSame('created', $rows['branch-2']['date_basis']);
        $this->assertSame('2026-02-01T00:00:00', substr($rows['branch-2']['registered_at'], 0, 19));
        $this->assertSame('business-1', $rows['branch-2']['business_uuid']);
        $this->assertArrayNotHasKey('permit_document_path', $rows['branch-2']);
    }

    public function test_unsubmitted_drafts_and_deleted_records_are_excluded_but_resubmissions_remain(): void
    {
        $this->signIn(); $this->business(1); $this->business(2, 'Unregistered'); $this->business(3, 'Verified', now()->toDateTimeString());
        $this->branch(1, 'Unregistered'); $this->branch(2, 'Unregistered'); $this->branch(3, 'Verified', 3);
        $this->branch(4, 'Verified', 1, now()->toDateTimeString());
        $this->submission('spa_branches', 2, '2026-06-01 00:00:00');
        $response = $this->getJson('/api/system/reports/registrations')->assertOk()->assertJsonPath('total', 2);
        $this->assertEqualsCanonicalizing(['business-1', 'branch-2'], collect($response->json('data'))->pluck('uuid')->all());
    }

    public function test_pagination_retains_every_distinct_record_and_never_merges_equal_business_names(): void
    {
        $this->signIn(); $this->business(1); $this->business(2); $this->branch(1);
        $ids = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson("/api/system/reports/registrations?per_page=1&page=$page")
                ->assertOk()->assertJsonPath('last_page', 3)->assertJsonCount(1, 'data');
            $ids[] = $response->json('data.0.uuid');
        }
        $this->assertCount(3, array_unique($ids));
        $this->getJson('/api/system/reports/registrations?per_page=9999')->assertUnprocessable();
    }

    public function test_permission_and_role_gates_protect_the_report(): void
    {
        $this->getJson('/api/system/reports/registrations')->assertUnauthorized();
        $this->signIn(permission: false);
        $this->getJson('/api/system/reports/registrations')->assertForbidden();
        $this->signIn('business_owner');
        $this->getJson('/api/system/reports/registrations')->assertForbidden();
    }

    public function test_an_empty_report_is_paginated_and_reads_do_not_write_registration_records(): void
    {
        $this->signIn();
        $this->getJson('/api/system/reports/registrations')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        $this->assertSame(0, DB::table('spa_businesses')->count());
        $this->assertSame(0, DB::table('spa_branches')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }
}

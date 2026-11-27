<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureBusinessSubscribed;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientBookingPauseTest extends TestCase
{
    private SpaBusiness $business;
    private SpaBranch $main;
    private SpaBranch $second;
    private User $mobileUser;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        Schema::disableForeignKeyConstraints();
        foreach ([
            '2026_01_01_000120_create_users_table.php',
            '2026_01_01_000170_create_user_permissions_table.php',
            '2026_01_01_000190_create_spa_businesses_table.php',
            '2026_01_01_000310_create_spa_branches_table.php',
            '2026_01_01_000320_create_spa_business_settings_table.php',
            '2026_01_01_000360_create_audit_logs_table.php',
            '2026_01_01_000530_create_clients_table.php',
            '2026_01_01_000650_create_appointments_table.php',
            '2026_11_28_010000_create_booking_no_show_waivers_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->withoutMiddleware(EnsureBusinessSubscribed::class);
        $owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::create(['owner_id' => $owner->id, 'business_name' => 'Test Spa']);
        $this->main = $this->branch('Main');
        $this->second = $this->branch('Second');
        $this->mobileUser = User::factory()->create(['role' => 'client']);
        $this->client = Client::create([
            'spa_business_id' => $this->business->id, 'spa_branch_id' => $this->main->id, 'user_id' => $this->mobileUser->id,
            'first_name' => 'Nicole', 'last_name' => 'Cruz',
        ]);
        $this->appointment($this->main, '2026-11-20');
        $this->appointment($this->main, '2026-11-21');
        $this->appointment($this->second, '2026-11-22');
    }

    public function test_staff_unpause_one_branch_and_the_next_miss_counts_as_one_on_the_app(): void
    {
        $staff = $this->frontOfficer(true);
        Sanctum::actingAs($staff);
        $this->getJson($this->profileUrl())->assertOk()
            ->assertJsonPath('data.can_unpause', true)
            ->assertJsonPath('data.branches.0.missed', 3)
            ->assertJsonCount(1, 'data.branches');
        $this->postJson($this->resetUrl($this->second), ['reason' => 'Client called'])->assertNotFound();
        $this->postJson($this->resetUrl($this->main), ['reason' => '   '])->assertUnprocessable();
        $this->postJson($this->resetUrl($this->main), ['reason' => 'Client called and explained.'])->assertNoContent();
        $this->getJson($this->profileUrl())->assertOk()->assertJsonCount(0, 'data.branches');
        $this->assertDatabaseCount('booking_no_show_waivers', 3);
        $this->assertSame(3, DB::table('appointments')->where('status', 'no_show')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'client.unpause_app_booking', 'spa_branch_id' => $this->main->id, 'user_id' => $staff->id]);
        $this->assertSame('Client called and explained.', AuditLog::where('event', 'client.unpause_app_booking')->sole()->new_values['reason']);
        $this->postJson($this->resetUrl($this->main), ['reason' => 'Retry'])->assertUnprocessable();

        Sanctum::actingAs($this->mobileUser);
        $this->getJson($this->appUrl($this->main))->assertOk()
            ->assertJsonPath('data.blocked', false)->assertJsonPath('data.missed', 0);
        $this->getJson($this->appUrl($this->second))->assertOk()
            ->assertJsonPath('data.blocked', true)->assertJsonPath('data.missed', 3);

        $this->appointment($this->main, '2026-11-22');
        $this->getJson($this->appUrl($this->main))->assertOk()
            ->assertJsonPath('data.blocked', false)->assertJsonPath('data.missed', 1);
        $this->appointment($this->main, '2026-11-24');
        $this->appointment($this->main, '2026-11-25');
        $this->getJson($this->appUrl($this->main))->assertOk()
            ->assertJsonPath('data.blocked', true)->assertJsonPath('data.missed', 3);
    }

    public function test_a_front_officer_without_booking_override_permission_cannot_unpause(): void
    {
        Sanctum::actingAs($this->frontOfficer(false));
        $this->getJson($this->profileUrl())->assertOk()->assertJsonPath('data.can_unpause', false);
        $this->postJson($this->resetUrl($this->main), ['reason' => 'Client called'])->assertForbidden();
        $this->assertDatabaseCount('booking_no_show_waivers', 0);
    }

    private function frontOfficer(bool $canOverride): User
    {
        $staff = User::factory()->create(['role' => 'front_officer']);
        $staff->setRelation('scopeBranches', collect([$this->main]));
        $staff->setRelation('scopeLevels', collect([$this->main->id => 'operation']));
        $staff->setRelation('permission', new UserPermission(['client_view' => true, 'policy_override' => $canOverride]));

        return $staff;
    }

    private function branch(string $name): SpaBranch
    {
        return SpaBranch::create([
            'spa_business_id' => $this->business->id, 'branch_name' => $name,
            'booking_overrides' => ['no_show_policy' => 'suspend', 'no_show_suspend_after' => 3],
        ]);
    }

    private function appointment(SpaBranch $branch, string $date): void
    {
        $uuid = (string) Str::uuid();
        DB::table('appointments')->insert([
            'uuid' => $uuid, 'appointment_number' => $uuid, 'client_id' => $this->client->id, 'spa_branch_id' => $branch->id,
            'appointment_type' => 'Reservation', 'appointment_date' => $date, 'appointment_time' => '10:00:00',
            'source' => 'Mobile', 'status' => 'no_show',
        ]);
    }

    private function profileUrl(): string
    {
        return "/api/business/client/{$this->client->uuid}/booking-pauses";
    }

    private function resetUrl(SpaBranch $branch): string
    {
        return $this->profileUrl()."/{$branch->uuid}/unpause";
    }

    private function appUrl(SpaBranch $branch): string
    {
        return "/api/client/spas/{$branch->uuid}/booking-status";
    }
}

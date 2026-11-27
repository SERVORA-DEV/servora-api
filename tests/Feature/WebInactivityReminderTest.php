<?php

namespace Tests\Feature;

use App\Mail\AccountNoticeMail;
use App\Models\AccountBranch;
use App\Models\AuditLog;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebInactivityReminderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'app.canonical_frontend_url' => 'https://servora.example.test',
            'mail.web_inactivity_reminder_days' => 30,
        ]);
        DB::purge('sqlite');

        foreach ([
            '2026_01_01_000080_create_personal_access_tokens_table.php',
            '2026_01_01_000120_create_users_table.php',
            '2026_01_01_000190_create_spa_businesses_table.php',
            '2026_01_01_000310_create_spa_branches_table.php',
            '2026_01_01_000340_create_account_branches_table.php',
            '2026_01_01_000360_create_audit_logs_table.php',
            '2026_11_27_000000_add_web_inactivity_to_users.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        Mail::fake();
    }

    public function test_one_reminder_per_inactive_period_and_using_the_site_starts_a_new_period(): void
    {
        $owner = $this->olderOwner();
        $this->olderOwner(['last_login_at' => now()->subDays(10)]);
        $this->olderOwner(['account_status' => 'Suspended']);
        $this->olderOwner(['email_verified_at' => null]);
        $this->olderOwner(['deleted_at' => now()]);
        $this->olderOwner(['role' => 'client']);
        $this->olderOwner()->createToken('Existing web session');
        $recentlySignedOut = $this->olderOwner();
        AuditLog::create([
            'user_id' => $recentlySignedOut->id,
            'table_name' => 'users',
            'record_id' => $recentlySignedOut->id,
            'action' => 'Logout',
            'new_values' => ['reason' => 'signed_out'],
        ]);

        $this->artisan('accounts:send-web-inactivity-reminders')->assertSuccessful();
        $this->artisan('accounts:send-web-inactivity-reminders')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(AccountNoticeMail::class, fn ($mail) => $mail->hasTo($owner->email)
            && $mail->actionUrl === 'https://servora.example.test/auth/login');

        Sanctum::actingAs($owner);
        $this->postJson('/api/auth/web-activity')->assertNoContent();
        $this->assertNotNull($owner->fresh()->last_web_activity_at);
        $this->assertNull($owner->fresh()->inactivity_reminder_sent_at);

        $this->travel(29)->days();
        $this->artisan('accounts:send-web-inactivity-reminders')->assertSuccessful();
        $this->assertCount(1, Mail::sent(AccountNoticeMail::class, fn ($mail) => $mail->hasTo($owner->email)));

        $this->travel(2)->days();
        $this->artisan('accounts:send-web-inactivity-reminders')->assertSuccessful();
        $this->assertCount(2, Mail::sent(AccountNoticeMail::class, fn ($mail) => $mail->hasTo($owner->email)));
    }

    public function test_activity_requires_an_authenticated_business_web_account(): void
    {
        $this->postJson('/api/auth/web-activity')->assertUnauthorized();

        $client = $this->olderOwner(['role' => 'client']);
        Sanctum::actingAs($client);
        $this->postJson('/api/auth/web-activity')->assertForbidden();
        $this->assertNull($client->fresh()->last_web_activity_at);
    }

    public function test_staff_reminder_links_to_their_business_sign_in_page(): void
    {
        $manager = $this->olderOwner(['role' => 'manager']);
        $business = SpaBusiness::factory()->create(['owner_id' => User::factory()->create(['role' => 'business_owner'])->id]);
        $branch = SpaBranch::factory()->create(['spa_business_id' => $business->id]);
        AccountBranch::create(['user_id' => $manager->id, 'spa_branch_id' => $branch->id, 'level' => AccountBranch::MANAGEMENT]);

        $this->artisan('accounts:send-web-inactivity-reminders')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(AccountNoticeMail::class, fn ($mail) => $mail->hasTo($manager->email)
            && $mail->actionUrl === 'https://servora.example.test/login/'.$business->uuid);
    }

    private function olderOwner(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'role' => 'business_owner',
            'created_at' => now()->subDays(45),
            'last_login_at' => now()->subDays(45),
        ]);
    }
}

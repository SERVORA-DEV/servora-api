<?php

namespace Tests\Feature;

use App\Models\AccountBranch;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserPermission;
use App\Repository\NotificationRepository;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Manual announcements from a System Administrator: who they reach, that nobody
// is reached twice or outside the audience, that a repeated send is one send,
// and that they stay apart from the automated notifications.
//
// Needs PostgreSQL (see phpunit.xml):
//   DB_PASSWORD=... php artisan test --filter=AnnouncementTest
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $otherAdmin;
    private User $ownerA;
    private User $ownerB;
    private User $manager;
    private User $clientOne;
    private User $clientTwo;
    private User $suspendedClient;
    private SpaBusiness $businessA;
    private SpaBusiness $businessB;
    private SpaBranch $branchA1;
    private SpaBranch $branchA2;

    private function admin(?array $grant = null): User
    {
        $user = User::factory()->create([
            'role' => 'system_administrator',
            'account_status' => 'Active',
            'email_verified_at' => now(),
        ]);
        $flags = array_fill_keys(AdminPermissions::keys(), $grant === null);
        foreach ($grant ?? [] as $key) {
            $flags[$key] = true;
        }
        UserPermission::create(['user_id' => $user->id, ...$flags]);

        return $user->fresh('permission');
    }

    private function business(User $owner, bool $subscribed): SpaBusiness
    {
        $business = SpaBusiness::factory()->create(['owner_id' => $owner->id, 'verification_status' => 'Verified']);
        $plan = SubscriptionPlan::firstOrCreate(['name' => 'Basic'], [
            'category' => 'Basic', 'monthly_price' => 500, 'billing_cycle' => 'Monthly',
            'max_branches' => 5, 'max_user_accounts' => 10, 'is_active' => true,
        ]);
        Subscription::create([
            'spa_business_id' => $business->id, 'subscription_plan_id' => $plan->id, 'billing_cycle' => 'Monthly',
            'starts_at' => now()->subMonths(2),
            // One that has lapsed is not "subscribed".
            'expires_at' => $subscribed ? now()->addMonth() : now()->subDay(),
            'status' => 'Active',
        ]);

        return $business;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin();
        $this->otherAdmin = $this->admin();

        $this->ownerA = User::factory()->create(['role' => 'business_owner', 'account_status' => 'Active']);
        $this->ownerB = User::factory()->create(['role' => 'business_owner', 'account_status' => 'Active']);
        $this->businessA = $this->business($this->ownerA, true);
        $this->businessB = $this->business($this->ownerB, false);

        $this->branchA1 = SpaBranch::factory()->create(['spa_business_id' => $this->businessA->id, 'verification_status' => 'Verified']);
        $this->branchA2 = SpaBranch::factory()->create(['spa_business_id' => $this->businessA->id, 'verification_status' => 'Verified']);

        $this->manager = User::factory()->create(['role' => 'manager', 'account_status' => 'Active']);
        AccountBranch::create(['user_id' => $this->manager->id, 'spa_branch_id' => $this->branchA1->id, 'level' => AccountBranch::MANAGEMENT]);

        $this->clientOne = User::factory()->create(['role' => 'client', 'account_status' => 'Active']);
        $this->clientTwo = User::factory()->create(['role' => 'client', 'account_status' => 'Active']);
        $this->suspendedClient = User::factory()->create(['role' => 'client', 'account_status' => 'Suspended']);

        Sanctum::actingAs($this->admin);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'audience' => 'admins',
            'scope' => 'all',
            'priority' => 'normal',
            'title' => 'Scheduled maintenance',
            'message' => 'The platform will be unavailable at midnight.',
            'request_id' => (string) Str::uuid(),
        ], $over);
    }

    private function notified(User $user)
    {
        return Notification::where('user_id', $user->id)->whereNotNull('announcement_id');
    }

    // ── Who may send ────────────────────────────────────────────────────

    public function test_an_administrator_without_the_send_permission_is_refused(): void
    {
        Sanctum::actingAs($this->admin(['dashboard_view']));

        $this->postJson('/api/system/announcements', $this->payload())->assertForbidden();
        $this->getJson('/api/system/announcements')->assertForbidden();
        $this->assertSame(0, Announcement::count());
    }

    public function test_a_business_owner_cannot_send_one(): void
    {
        Sanctum::actingAs($this->ownerA);

        $this->postJson('/api/system/announcements', $this->payload())->assertForbidden();
        $this->assertSame(0, Announcement::count());
    }

    // ── Who it reaches ──────────────────────────────────────────────────

    public function test_administrators_get_it_and_the_sender_does_not(): void
    {
        $this->postJson('/api/system/announcements', $this->payload())->assertCreated()
            ->assertJsonPath('announcement.recipient_count', 1);

        $this->assertSame(1, $this->notified($this->otherAdmin)->count());
        $this->assertSame(0, $this->notified($this->admin)->count());
        $this->assertSame(0, $this->notified($this->ownerA)->count());
    }

    public function test_all_businesses_reaches_only_owners_with_a_live_subscription(): void
    {
        $this->postJson('/api/system/announcements', $this->payload(['audience' => 'businesses']))->assertCreated();

        $this->assertSame(1, $this->notified($this->ownerA)->count());
        $this->assertSame(0, $this->notified($this->ownerB)->count(), 'a lapsed subscription is not eligible');
        $this->assertSame(0, $this->notified($this->manager)->count());
    }

    public function test_a_selected_business_that_is_not_eligible_is_refused_and_nothing_is_sent(): void
    {
        $this->postJson('/api/system/announcements', $this->payload([
            'audience' => 'businesses', 'scope' => 'selected', 'targets' => [$this->businessB->uuid],
        ]))->assertUnprocessable()->assertJsonValidationErrors('targets');

        $this->assertSame(0, Announcement::count());
        $this->assertSame(0, Notification::whereNotNull('announcement_id')->count());
    }

    public function test_two_selected_branches_of_one_business_reach_the_owner_once(): void
    {
        $this->postJson('/api/system/announcements', $this->payload([
            'audience' => 'branches', 'scope' => 'selected', 'targets' => [$this->branchA1->uuid, $this->branchA2->uuid],
        ]))->assertCreated()->assertJsonPath('announcement.recipient_count', 2);

        // The owner once (not once per branch) and the manager who runs a selected branch.
        $this->assertSame(1, $this->notified($this->ownerA)->count());
        $this->assertSame(1, $this->notified($this->manager)->count());
    }

    public function test_clients_reach_active_accounts_only_and_are_not_signed_by_a_person(): void
    {
        $this->postJson('/api/system/announcements', $this->payload(['audience' => 'clients']))->assertCreated()
            ->assertJsonPath('announcement.recipient_count', 2);

        $this->assertSame(1, $this->notified($this->clientOne)->count());
        $this->assertSame(0, $this->notified($this->suspendedClient)->count());
        $this->assertStringContainsString('— Servora', $this->notified($this->clientOne)->first()->message);
        $this->assertStringNotContainsString($this->admin->first_name, $this->notified($this->clientOne)->first()->message);
    }

    public function test_a_selection_cannot_name_someone_outside_the_audience(): void
    {
        // An administrator's uuid is not a client.
        $this->postJson('/api/system/announcements', $this->payload([
            'audience' => 'clients', 'scope' => 'selected', 'targets' => [$this->otherAdmin->uuid],
        ]))->assertUnprocessable();

        $this->assertSame(0, Announcement::count());
    }

    public function test_the_preview_matches_what_is_sent(): void
    {
        $this->postJson('/api/system/announcements/preview', ['audience' => 'clients', 'scope' => 'all'])
            ->assertOk()->assertJsonPath('recipients', 2);
        $this->postJson('/api/system/announcements/preview', [
            'audience' => 'clients', 'scope' => 'selected', 'targets' => [$this->clientOne->uuid],
        ])->assertOk()->assertJsonPath('recipients', 1);
    }

    // ── The same send twice ─────────────────────────────────────────────

    public function test_the_same_request_id_is_one_announcement(): void
    {
        $body = $this->payload(['audience' => 'clients']);

        $this->postJson('/api/system/announcements', $body)->assertCreated()->assertJsonPath('duplicate', false);
        $this->postJson('/api/system/announcements', $body)->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, Announcement::count());
        $this->assertSame(1, $this->notified($this->clientOne)->count());
    }

    public function test_another_administrators_request_id_is_not_echoed_back(): void
    {
        $body = $this->payload();
        $this->postJson('/api/system/announcements', $body)->assertCreated();

        Sanctum::actingAs($this->otherAdmin);
        $this->postJson('/api/system/announcements', $body)->assertStatus(409);
    }

    // ── What a recipient sees, and the record ───────────────────────────

    public function test_an_urgent_one_says_so_in_the_title_and_keeps_its_priority(): void
    {
        $this->postJson('/api/system/announcements', $this->payload(['audience' => 'clients', 'priority' => 'urgent']))->assertCreated();

        $n = $this->notified($this->clientOne)->first();
        $this->assertSame('Urgent: Scheduled maintenance', $n->title);
        $this->assertSame('urgent', $n->priority);
        $this->assertSame('Scheduled maintenance', Announcement::first()->title, 'the record keeps what was written');
    }

    public function test_it_is_audited_and_listed_in_the_history_with_how_many_have_read_it(): void
    {
        $this->postJson('/api/system/announcements', $this->payload(['audience' => 'clients']))->assertCreated();

        $this->assertTrue(AuditLog::where('table_name', 'announcements')->where('action', 'Create')->where('user_id', $this->admin->id)->exists());

        $this->getJson('/api/system/announcements')->assertOk()
            ->assertJsonPath('data.0.title', 'Scheduled maintenance')
            ->assertJsonPath('data.0.recipient_count', 2)
            ->assertJsonPath('data.0.read_count', 0);

        // One recipient opens theirs through the normal notification feed.
        Sanctum::actingAs($this->clientOne);
        $id = $this->notified($this->clientOne)->value('id');
        $this->patchJson("/api/client/notifications/{$id}/read")->assertSuccessful();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/system/announcements')->assertJsonPath('data.0.read_count', 1);
    }

    public function test_the_history_can_be_filtered(): void
    {
        $this->postJson('/api/system/announcements', $this->payload(['audience' => 'clients', 'title' => 'Alpha notice']))->assertCreated();
        $this->postJson('/api/system/announcements', $this->payload(['audience' => 'businesses', 'priority' => 'important', 'title' => 'Beta notice']))->assertCreated();

        $this->getJson('/api/system/announcements?audience=businesses')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Beta notice');
        $this->getJson('/api/system/announcements?priority=normal')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Alpha notice');
        $this->getJson('/api/system/announcements?q=alp')->assertJsonCount(1, 'data');
    }

    public function test_automated_notifications_stay_apart_from_announcements(): void
    {
        app(NotificationRepository::class)->create($this->clientOne->id, 'Booking confirmed', 'See you soon.', 'Appointment');

        $auto = Notification::where('title', 'Booking confirmed')->first();
        $this->assertNull($auto->announcement_id);
        $this->assertNull($auto->priority);

        $this->postJson('/api/system/announcements', $this->payload(['audience' => 'clients']))->assertCreated();
        $this->getJson('/api/system/announcements')->assertJsonPath('data.0.recipient_count', 2);
        $this->assertSame(2, Notification::whereNotNull('announcement_id')->count());
    }

    // ── Validation and the pick-lists ───────────────────────────────────

    public function test_bad_input_is_rejected(): void
    {
        $this->postJson('/api/system/announcements', $this->payload(['priority' => 'loud']))->assertUnprocessable()->assertJsonValidationErrors('priority');
        $this->postJson('/api/system/announcements', $this->payload(['audience' => 'everyone']))->assertUnprocessable()->assertJsonValidationErrors('audience');
        $this->postJson('/api/system/announcements', $this->payload(['title' => '  ']))->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson('/api/system/announcements', $this->payload(['scope' => 'selected']))->assertUnprocessable();
        // Administrators are sent to all at once.
        $this->postJson('/api/system/announcements', $this->payload(['scope' => 'selected', 'targets' => [$this->otherAdmin->uuid]]))->assertUnprocessable();
        $this->assertSame(0, Announcement::count());
    }

    public function test_sending_to_no_one_is_refused(): void
    {
        // Only the sender is an administrator now.
        User::where('id', $this->otherAdmin->id)->update(['account_status' => 'Inactive']);

        $this->postJson('/api/system/announcements', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('audience');
        $this->assertSame(0, Announcement::count());
    }

    public function test_the_client_pick_list_offers_active_clients_with_a_masked_email(): void
    {
        $res = $this->getJson('/api/system/announcements/recipient-options?audience=clients')->assertOk();

        $ids = collect($res->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->clientOne->uuid));
        $this->assertFalse($ids->contains($this->suspendedClient->uuid));
        $this->assertStringNotContainsString($this->clientOne->email, $res->getContent());
        $this->assertStringContainsString('•', $res->getContent());
    }
}

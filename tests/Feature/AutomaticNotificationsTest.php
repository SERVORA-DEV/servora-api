<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Notification;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserPermission;
use App\Repository\NotificationRepository;
use App\Service\Notifications\PushSender;
use App\Service\System\AnnouncementService;
use App\Support\Access\AdminPermissions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The notices nobody sends by hand: booking reminders (appointments:send-reminders), the
// app-update notice (mobile:announce-release), the push each one queues, and each role's
// feed reading them back (client app, owner/staff bell, administrator bell).
//
// Needs PostgreSQL (see phpunit.xml): the local throwaway database only.
class AutomaticNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $clientUser;
    private SpaBusiness $business;
    private SpaBranch $branch;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin_access.require_two_factor' => false, 'admin_access.allowed_ips' => []]);
        // Saturday noon, Manila.
        $this->travelTo(Carbon::parse('2026-11-21 12:00:00', 'Asia/Manila'));

        $this->owner = User::factory()->create(['role' => 'business_owner', 'account_status' => 'Active', 'email_verified_at' => now()]);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id, 'verification_status' => 'Verified']);
        $plan = SubscriptionPlan::firstOrCreate(['name' => 'Basic'], [
            'category' => 'Basic', 'monthly_price' => 500, 'billing_cycle' => 'Monthly',
            'max_branches' => 5, 'max_user_accounts' => 10, 'is_active' => true,
        ]);
        Subscription::create([
            'spa_business_id' => $this->business->id, 'subscription_plan_id' => $plan->id, 'billing_cycle' => 'Monthly',
            'starts_at' => now()->subMonth(), 'expires_at' => now()->addMonth(), 'status' => 'Active',
        ]);
        $this->branch = SpaBranch::factory()->create([
            'spa_business_id' => $this->business->id, 'verification_status' => 'Verified', 'operating_status' => 'Active',
        ]);

        $this->clientUser = User::factory()->create(['role' => 'client', 'account_status' => 'Active']);
        $this->client = Client::factory()->create(['spa_business_id' => $this->business->id, 'user_id' => $this->clientUser->id]);
    }

    /** A scheduled booking at [$at], made [$bookedAt]. */
    private function booking(string $at, string $bookedAt, ?Client $client = null): Appointment
    {
        $start = Carbon::parse($at, 'Asia/Manila');
        $appointment = Appointment::create([
            'spa_branch_id' => $this->branch->id,
            'client_id' => ($client ?? $this->client)->id,
            'appointment_number' => 'APT-'.uniqid(),
            'appointment_date' => $start->toDateString(),
            'appointment_time' => $start->format('H:i'),
            'appointment_type' => 'Reservation',
            'source' => 'Mobile',
            'status' => 'scheduled',
        ]);
        $appointment->forceFill(['created_at' => Carbon::parse($bookedAt, 'Asia/Manila')])->saveQuietly();

        return $appointment->fresh();
    }

    private function reminders(?User $user = null)
    {
        return Notification::where('user_id', ($user ?? $this->clientUser)->id)->where('title', 'Booking reminder');
    }

    // ── Booking reminders ───────────────────────────────────────────────

    public function test_a_booking_inside_the_reminder_window_is_reminded_once(): void
    {
        // Tomorrow 10:00 is 22 hours away; the spa's default window is 24 hours.
        $booking = $this->booking('2026-11-22 10:00', '2026-11-18 09:00');

        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->artisan('appointments:send-reminders')->assertSuccessful();

        $this->assertSame(1, $this->reminders()->count());
        $reminder = $this->reminders()->first();
        $this->assertSame('Appointment', $reminder->type);
        $this->assertSame($booking->uuid, $reminder->reference_uuid);
        $this->assertStringContainsString('Sun, Nov 22 at 10:00 AM', $reminder->message);
        $this->assertNotNull($booking->fresh()->reminder_sent_at);
    }

    public function test_one_further_ahead_waits_until_it_is_inside_the_window(): void
    {
        $booking = $this->booking('2026-11-24 10:00', '2026-11-18 09:00');

        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->assertSame(0, $this->reminders()->count());
        $this->assertNull($booking->fresh()->reminder_sent_at);

        // Two days later it is 22 hours away.
        $this->travelTo(Carbon::parse('2026-11-23 12:00:00', 'Asia/Manila'));
        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->assertSame(1, $this->reminders()->count());
    }

    public function test_a_booking_made_inside_the_window_is_not_nagged(): void
    {
        $booking = $this->booking('2026-11-22 10:00', '2026-11-21 11:00');

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        $this->assertSame(0, $this->reminders()->count());
        $this->assertNotNull($booking->fresh()->reminder_sent_at, 'looked at, so it is not checked again');
    }

    public function test_reminders_turned_off_for_the_branch_send_nothing(): void
    {
        $this->branch->forceFill(['booking_overrides' => ['send_client_reminder' => false]])->saveQuietly();
        $this->booking('2026-11-22 10:00', '2026-11-18 09:00');

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        $this->assertSame(0, $this->reminders()->count());
    }

    public function test_a_walk_in_without_an_app_account_gets_none_and_a_started_visit_none(): void
    {
        $walkIn = Client::factory()->create(['spa_business_id' => $this->business->id, 'user_id' => null]);
        $this->booking('2026-11-22 10:00', '2026-11-18 09:00', $walkIn);
        $this->booking('2026-11-21 11:00', '2026-11-18 09:00');

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        $this->assertSame(0, Notification::where('title', 'Booking reminder')->count());
    }

    public function test_every_notification_queues_its_push(): void
    {
        $push = $this->mock(PushSender::class);
        $push->shouldReceive('queue')->once();
        $this->booking('2026-11-22 10:00', '2026-11-18 09:00');

        $this->artisan('appointments:send-reminders')->assertSuccessful();
    }

    // ── The app-update notice ───────────────────────────────────────────

    private function release(int $build, ?string $url = 'https://example.com/servora.apk', string $version = '1.0.3'): void
    {
        config(['mobile.latest_build' => $build, 'mobile.latest_version' => $version, 'mobile.apk_url' => $url]);
    }

    private function updateNotices()
    {
        return Notification::where('title', 'A new Servora app is ready');
    }

    public function test_the_first_run_only_records_where_the_app_is(): void
    {
        $this->release(5, version: '1.0.2');

        $this->artisan('mobile:announce-release')->assertSuccessful();

        $this->assertSame(0, $this->updateNotices()->count());
        $this->assertSame('baseline', Announcement::where('request_id', 'app-release-5')->value('status'));
    }

    public function test_a_higher_build_tells_every_active_client_once(): void
    {
        $suspended = User::factory()->create(['role' => 'client', 'account_status' => 'Suspended']);
        $other = User::factory()->create(['role' => 'client', 'account_status' => 'Active']);
        $this->release(5, version: '1.0.2');
        $this->artisan('mobile:announce-release');

        $this->release(6);
        $this->artisan('mobile:announce-release')->assertSuccessful();
        $this->artisan('mobile:announce-release')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [$this->clientUser->id, $other->id],
            $this->updateNotices()->pluck('user_id')->all(),
        );
        $this->assertSame(0, $this->updateNotices()->whereIn('user_id', [$suspended->id, $this->owner->id])->count());
        $notice = $this->updateNotices()->where('user_id', $this->clientUser->id)->first();
        $this->assertSame('System', $notice->type);
        $this->assertStringContainsString('Version 1.0.3 is ready. Tap to update.', $notice->message);

        $announcement = Announcement::where('request_id', 'app-release-6')->first();
        $this->assertSame('sent', $announcement->status);
        $this->assertSame(2, $announcement->recipient_count);
        $this->assertNull($announcement->sender_id);

        // The client app is told what tapping it opens.
        Sanctum::actingAs($this->clientUser);
        $feed = $this->getJson('/api/client/notifications')->assertOk()->json('data');
        $row = collect($feed)->firstWhere('title', 'A new Servora app is ready');
        $this->assertSame('app_update', $row['kind']);
    }

    public function test_nothing_is_announced_without_a_download_or_a_build(): void
    {
        $this->release(6, url: null);
        $this->artisan('mobile:announce-release')->assertSuccessful();
        $this->release(0);
        $this->artisan('mobile:announce-release')->assertSuccessful();

        $this->assertSame(0, Announcement::count());
    }

    public function test_going_back_to_an_older_build_is_not_news(): void
    {
        $this->release(5);
        $this->artisan('mobile:announce-release');
        $this->release(7);
        $this->artisan('mobile:announce-release');
        $before = $this->updateNotices()->count();

        $this->release(6);
        $this->artisan('mobile:announce-release')->assertSuccessful();

        $this->assertSame($before, $this->updateNotices()->count());
        $this->assertSame('baseline', Announcement::where('request_id', 'app-release-6')->value('status'));
    }

    public function test_the_history_shows_the_automatic_send_and_not_the_starting_point(): void
    {
        $this->release(5);
        $this->artisan('mobile:announce-release');
        $this->release(6);
        $this->artisan('mobile:announce-release');

        $rows = app(AnnouncementService::class)->history([], 20)->response()->getData(true)['data'];

        $this->assertCount(1, $rows);
        $this->assertSame('Servora (automatic)', $rows[0]['sender']);
        $this->assertSame(1, $rows[0]['recipient_count']);
    }

    // ── Each role's feed ────────────────────────────────────────────────

    public function test_the_client_feed_reads_and_marks_a_reminder(): void
    {
        $this->booking('2026-11-22 10:00', '2026-11-18 09:00');
        $this->artisan('appointments:send-reminders');
        Sanctum::actingAs($this->clientUser);

        $row = collect($this->getJson('/api/client/notifications')->assertOk()->json('data'))->firstWhere('title', 'Booking reminder');
        $this->assertNotNull($row['appointment_uuid']);

        $this->patchJson("/api/client/notifications/{$row['id']}/read")->assertOk();
        $this->assertTrue((bool) Notification::find($row['id'])->is_read);
    }

    public function test_the_owner_bell_lists_and_marks_its_notices(): void
    {
        $notice = app(NotificationRepository::class)->create($this->owner->id, 'Your business is verified', 'You can take bookings.', 'Verification');
        Sanctum::actingAs($this->owner);

        $ids = collect($this->getJson('/api/business/notifications')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($notice->id));

        $this->postJson("/api/business/notifications/{$notice->id}/read")->assertSuccessful();
        $this->postJson('/api/business/notifications/read-all')->assertSuccessful();
        $this->assertTrue((bool) $notice->fresh()->is_read);
    }

    public function test_the_administrator_bell_lists_and_marks_its_notices(): void
    {
        $admin = User::factory()->create(['role' => 'system_administrator', 'account_status' => 'Active', 'email_verified_at' => now()]);
        UserPermission::create(['user_id' => $admin->id, ...array_fill_keys(AdminPermissions::keys(), true)]);
        $notice = app(NotificationRepository::class)->create($admin->id, 'New business to verify', 'Lotus Leaf Spa sent its documents.', 'Verification');
        Sanctum::actingAs($admin->fresh('permission'));

        $ids = collect($this->getJson('/api/system/notifications')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($notice->id));

        $this->postJson("/api/system/notifications/{$notice->id}/read")->assertSuccessful();
        $this->assertTrue((bool) $notice->fresh()->is_read);
    }
}

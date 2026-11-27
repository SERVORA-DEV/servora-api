<?php

namespace Tests\Feature;

use App\Mail\SubscriptionReminderMail;
use App\Models\Notification;
use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Service\Notifications\PushSender;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class SubscriptionReminderDeliveryTest extends TestCase
{
    private const COMMAND = 'subscriptions:notify-almost-due';
    private const ADDRESS = 'owner@example.test';
    private User $owner;
    private SpaBusiness $business;
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'cache.stores.durable' => ['driver' => 'array'],
            'app.canonical_frontend_url' => 'https://servora.example.test',
        ]);
        DB::purge('sqlite');
        Schema::disableForeignKeyConstraints();
        foreach ([
            '2026_01_01_000100_create_subscription_plans_table.php',
            '2026_01_01_000110_create_system_settings_table.php',
            '2026_01_01_000120_create_users_table.php',
            '2026_01_01_000180_create_notifications_table.php',
            '2026_01_01_000190_create_spa_businesses_table.php',
            '2026_01_01_000320_create_spa_business_settings_table.php',
            '2026_01_01_000490_create_subscriptions_table.php',
            '2026_11_28_000000_add_pending_subscription_reminder_email.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $push = Mockery::mock(PushSender::class);
        $push->shouldReceive('queue');
        $this->app->instance(PushSender::class, $push);
        $this->travelTo(Carbon::parse('2026-11-27 00:00:00'));
        $this->owner = User::factory()->create(['role' => 'business_owner', 'email' => self::ADDRESS]);
        $this->business = SpaBusiness::create(['owner_id' => $this->owner->id, 'business_name' => 'Test Spa']);
        $this->plan = SubscriptionPlan::create([
            'category' => 'Basic', 'name' => 'Basic', 'monthly_price' => 599, 'billing_cycle' => 'Monthly',
            'max_branches' => 1, 'max_user_accounts' => 2,
        ]);
    }

    public function test_a_due_reminder_reaches_the_web_and_email_once_even_with_a_pending_checkout(): void
    {
        Mail::fake();
        $subscription = $this->subscription();
        $this->subscription(['status' => 'Pending']);

        $this->artisan(self::COMMAND)->assertSuccessful();
        $web = Notification::sole();
        $this->assertSame($this->owner->id, $web->user_id);
        $this->assertSame('Subscription', $web->type);
        Mail::assertSent(SubscriptionReminderMail::class, fn ($mail) => $mail->hasTo(self::ADDRESS)
            && $mail->heading === $web->title
            && $mail->body === $web->message
            && $mail->actionUrl === 'https://servora.example.test/business/subscription');
        $this->assertNull($subscription->fresh()->expiry_reminder_email_moment);

        $this->artisan(self::COMMAND, ['--retry-email' => true])->assertSuccessful();
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertDatabaseCount('notifications', 1);
        Mail::assertSentCount(1);
    }

    public function test_a_mail_failure_leaves_the_web_alert_visible_and_only_retries_the_email(): void
    {
        $subscription = $this->subscription();
        $this->runWithEmailFailure();
        $web = Notification::sole();
        $this->assertSame('first', $subscription->fresh()->expiry_reminder_email_moment);

        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-27 01:15:00'));
        $this->artisan(self::COMMAND, ['--retry-email' => true])->assertSuccessful();
        Mail::assertSent(SubscriptionReminderMail::class, fn ($mail) => $mail->hasTo(self::ADDRESS) && $mail->body === $web->message);
        $this->assertNull($subscription->fresh()->expiry_reminder_email_moment);
        $this->artisan(self::COMMAND, ['--retry-email' => true])->assertSuccessful();
        $this->assertDatabaseCount('notifications', 1);
        Mail::assertSentCount(1);
    }

    public function test_a_superseded_trial_email_is_replaced_by_the_current_reminder(): void
    {
        $subscription = $this->subscription(['is_trial' => true]);
        $this->runWithEmailFailure();

        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-29 00:00:00'));
        $this->artisan(self::COMMAND, ['--retry-email' => true])->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertNull($subscription->fresh()->expiry_reminder_email_moment);
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertDatabaseCount('notifications', 2);
        Mail::assertSent(SubscriptionReminderMail::class, fn ($mail) => $mail->heading === 'Free Trial Ending'
            && str_contains($mail->body, 'tomorrow')
            && $mail->actionUrl === 'https://servora.example.test/business/plans?paid=1');
    }

    public function test_the_early_reminder_preference_applies_to_both_channels_and_overdue_notices_still_arrive(): void
    {
        Mail::fake();
        $this->subscription();
        SpaBusinessSetting::create(['spa_business_id' => $this->business->id, 'notifications' => [
            'subscription_renewal_alert' => false,
        ]]);

        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertDatabaseCount('notifications', 0);
        Mail::assertNothingSent();

        $this->travelTo(Carbon::parse('2026-12-01 00:00:00'));
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertDatabaseHas('notifications', ['title' => 'Subscription Payment Overdue', 'user_id' => $this->owner->id]);
        Mail::assertSent(SubscriptionReminderMail::class, fn ($mail) => $mail->hasTo(self::ADDRESS)
            && $mail->heading === 'Subscription Payment Overdue' && $mail->alwaysSent);

        $this->travelTo(Carbon::parse('2026-12-08 00:00:00'));
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertDatabaseHas('notifications', ['title' => 'Subscription Access Paused', 'user_id' => $this->owner->id]);
        Mail::assertSent(SubscriptionReminderMail::class, fn ($mail) => $mail->hasTo(self::ADDRESS)
            && $mail->heading === 'Subscription Access Paused' && $mail->alwaysSent);
    }

    public function test_an_overlapping_run_leaves_the_reminder_to_the_run_that_holds_the_lock(): void
    {
        Mail::fake();
        $subscription = $this->subscription();
        $lock = Cache::store('durable')->lock("subscription-reminder:{$subscription->id}", 600);
        $this->assertTrue($lock->get());
        try {
            $this->artisan(self::COMMAND)->assertSuccessful();
            $this->assertDatabaseCount('notifications', 0);
            Mail::assertNothingSent();
        } finally {
            $lock->release();
        }

        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertDatabaseCount('notifications', 1);
        Mail::assertSentCount(1);
    }

    private function runWithEmailFailure(): void
    {
        $mailManager = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->with(self::ADDRESS)->andThrow(new TransportException('Temporary email failure'));
        try {
            $this->artisan(self::COMMAND)->assertSuccessful();
        } finally {
            Mail::swap($mailManager);
        }
    }

    private function subscription(array $attributes = []): Subscription
    {
        return Subscription::create($attributes + [
            'spa_business_id' => $this->business->id, 'subscription_plan_id' => $this->plan->id,
            'billing_cycle' => 'Monthly', 'status' => 'Active', 'expires_at' => '2026-11-30 12:00:00',
        ]);
    }
}

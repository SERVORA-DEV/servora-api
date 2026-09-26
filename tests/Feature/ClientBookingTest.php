<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Billing;
use App\Models\BranchSchedule;
use App\Models\BranchService;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Queue;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Service\Business\AppointmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The mobile client's own-account endpoints under /api/client: their
// bookings (list, detail, cancel, reschedule, queue, review), favorites,
// receipts, notification feed and password — plus the public reviews list
// and the ratings on the spa cards/page.
//
// Postgres only (see TherapistAvailabilityTest for why):
//   php artisan test --filter=ClientBookingTest
class ClientBookingTest extends TestCase
{
    use RefreshDatabase;

    // A Tuesday comfortably in the future, so "choose a future time" and
    // "can still change it" hold without freezing the clock.
    private const DATE = '2027-03-02';
    private const DAY = 'Tuesday';

    private User $owner;
    private SpaBusiness $business;
    private SpaBranch $branch;
    private Staff $therapist;
    private ServiceVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create([
            'spa_business_id' => $this->business->id,
            'verification_status' => 'Verified',
            'operating_status' => 'Active',
            'listing_visible' => true,
            'latitude' => 7.07,
            'longitude' => 125.61,
        ]);

        $this->therapist = Staff::factory()->create([
            'spa_branch_id' => $this->branch->id,
            'role' => 'therapist',
            'status' => 'active',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
        ]);

        $service = Service::factory()->create(['spa_business_id' => $this->business->id, 'name' => 'Swedish Massage']);
        $this->variant = ServiceVariant::factory()->create([
            'service_id' => $service->id,
            'duration_minutes' => 60,
        ]);
        BranchService::factory()->create([
            'spa_branch_id' => $this->branch->id,
            'service_variant_id' => $this->variant->id,
        ]);
    }

    private function client(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'client',
            'password' => Hash::make('secret-pass'),
        ], $attributes));
    }

    private function book(User $user, array $overrides = []): string
    {
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/client/appointments', array_merge([
            'spa_branch_uuid' => $this->branch->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => '10:00',
            'services' => [['service_variant_uuid' => $this->variant->uuid]],
            'client' => ['first_name' => 'Mia', 'last_name' => 'Reyes', 'phone_number' => '0917' . random_int(1000000, 9999999)],
        ], $overrides));

        $response->assertSuccessful();

        return $response->json('data.uuid');
    }

    private function complete(string $uuid, float $amount = 700): Appointment
    {
        $appointment = Appointment::where('uuid', $uuid)->firstOrFail();
        $appointment->update(['status' => Appointment::STATUS_COMPLETED, 'completed_at' => now()]);

        $billing = Billing::create([
            'appointment_id' => $appointment->id,
            'spa_business_id' => $this->business->id,
            'spa_branch_id' => $this->branch->id,
            'billing_type' => 'Appointment',
            'billing_number' => 'BIL-' . uniqid(),
            'amount' => $amount,
            'status' => 'Paid',
            'issued_at' => now(),
            'paid_at' => now(),
        ]);
        Payment::create([
            'billing_id' => $billing->id,
            'spa_business_id' => $this->business->id,
            'spa_branch_id' => $this->branch->id,
            'payment_method' => 'GCash',
            'amount' => $amount,
            'payment_status' => 'Paid',
            'paid_at' => now(),
        ]);

        return $appointment;
    }

    // ── Bookings ─────────────────────────────────────────────────────────

    public function test_my_bookings_lists_only_my_own(): void
    {
        $me = $this->client();
        $mine = $this->book($me);
        $this->book($this->client());

        Sanctum::actingAs($me);
        $this->getJson('/api/client/appointments?scope=upcoming')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $mine)
            ->assertJsonPath('data.0.branch.name', $this->branch->branch_name)
            ->assertJsonPath('data.0.services.0.name', 'Swedish Massage')
            ->assertJsonPath('data.0.can_cancel', true)
            ->assertJsonPath('data.0.can_review', false);

        $this->getJson('/api/client/appointments?scope=cancelled')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_someone_elses_booking_is_not_found(): void
    {
        $theirs = $this->book($this->client());

        Sanctum::actingAs($this->client());
        $this->getJson("/api/client/appointments/{$theirs}")->assertNotFound();
        $this->postJson("/api/client/appointments/{$theirs}/cancel")->assertNotFound();
        $this->patchJson("/api/client/appointments/{$theirs}/reschedule", ['appointment_date' => self::DATE, 'appointment_time' => '14:00'])->assertNotFound();
    }

    public function test_a_client_can_cancel_their_scheduled_booking(): void
    {
        $me = $this->client();
        $uuid = $this->book($me);

        $this->postJson("/api/client/appointments/{$uuid}/cancel", ['reason' => 'Change of plans'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Change of plans')
            ->assertJsonPath('data.can_cancel', false);

        $this->getJson('/api/client/appointments?scope=cancelled')->assertJsonCount(1, 'data');
        // Again is refused, not silently accepted.
        $this->postJson("/api/client/appointments/{$uuid}/cancel")->assertStatus(422);
    }

    public function test_a_checked_in_booking_cannot_be_changed_from_the_app(): void
    {
        $me = $this->client();
        $uuid = $this->book($me);
        Appointment::where('uuid', $uuid)->update(['status' => Appointment::STATUS_CHECKED_IN, 'check_in_at' => now()]);

        $this->postJson("/api/client/appointments/{$uuid}/cancel")->assertStatus(422);
        $this->patchJson("/api/client/appointments/{$uuid}/reschedule", ['appointment_date' => self::DATE, 'appointment_time' => '14:00'])->assertStatus(422);
    }

    public function test_a_client_can_reschedule_within_opening_hours(): void
    {
        BranchSchedule::create([
            'spa_branch_id' => $this->branch->id,
            'day_of_week' => 'Wednesday',
            'opening_time' => '09:00:00',
            'closing_time' => '18:00:00',
            'is_closed' => false,
        ]);
        BranchSchedule::create(['spa_branch_id' => $this->branch->id, 'day_of_week' => 'Thursday', 'is_closed' => true]);

        $uuid = $this->book($this->client());

        $this->patchJson("/api/client/appointments/{$uuid}/reschedule", ['appointment_date' => '2027-03-04', 'appointment_time' => '10:00'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The branch is closed on this day.');

        $this->patchJson("/api/client/appointments/{$uuid}/reschedule", ['appointment_date' => '2027-03-03', 'appointment_time' => '15:30'])
            ->assertOk()
            ->assertJsonPath('data.appointment_date', '2027-03-03')
            ->assertJsonPath('data.appointment_time', '15:30')
            ->assertJsonPath('data.status', 'scheduled');

        $this->patchJson("/api/client/appointments/{$uuid}/reschedule", ['appointment_date' => '2020-01-01', 'appointment_time' => '10:00'])
            ->assertStatus(422);
    }

    public function test_reschedule_is_refused_when_the_chosen_therapist_is_off(): void
    {
        StaffSchedule::create(['staff_id' => $this->therapist->id, 'day_of_week' => self::DAY, 'start_time' => '09:00:00', 'end_time' => '18:00:00']);
        StaffSchedule::create(['staff_id' => $this->therapist->id, 'day_of_week' => 'Wednesday', 'is_day_off' => true]);

        $uuid = $this->book($this->client(), ['requested_therapist_uuid' => $this->therapist->uuid]);

        $this->patchJson("/api/client/appointments/{$uuid}/reschedule", ['appointment_date' => '2027-03-03', 'appointment_time' => '10:00'])
            ->assertStatus(422);
        $this->assertStringStartsWith("Ana Cruz isn't available then.", $this->patchJson("/api/client/appointments/{$uuid}/reschedule", ['appointment_date' => '2027-03-03', 'appointment_time' => '10:00'])->json('message'));

        // Moving later the same day overlaps only this booking's own slot,
        // which must not count against it.
        $this->patchJson("/api/client/appointments/{$uuid}/reschedule", ['appointment_date' => self::DATE, 'appointment_time' => '10:30'])
            ->assertOk()
            ->assertJsonPath('data.therapists.0.name', 'Ana Cruz');
    }

    public function test_queue_position_counts_waiting_clients_ahead(): void
    {
        $me = $this->client();
        $mine = $this->book($me, ['appointment_time' => '11:00']);

        $service = app(AppointmentService::class);
        $first = $this->book($this->client(), ['appointment_time' => '10:00']);
        $second = $this->book($this->client(), ['appointment_time' => '10:30']);

        $this->travelTo(now()); // keeps created_at ordering deterministic below
        $service->checkIn($this->owner, $first);
        $this->travel(1)->seconds();
        $service->checkIn($this->owner, $second);
        $this->travel(1)->seconds();
        $service->checkIn($this->owner, $mine);
        $service->callQueue($this->owner, $first);

        Sanctum::actingAs($me);
        $response = $this->getJson("/api/client/appointments/{$mine}/queue")->assertOk();
        $response->assertJsonPath('data.in_queue', true)
            ->assertJsonPath('data.ahead', 1)
            ->assertJsonPath('data.queue_status', 'Waiting')
            ->assertJsonPath('data.now_serving', Queue::whereHas('appointment', fn ($q) => $q->where('uuid', $first))->value('queue_number'))
            ->assertJsonPath('data.estimated_wait_minutes', 60);
    }

    public function test_a_booking_not_yet_checked_in_has_no_queue_ticket(): void
    {
        $uuid = $this->book($this->client());

        $this->getJson("/api/client/appointments/{$uuid}/queue")
            ->assertOk()
            ->assertJsonPath('data.in_queue', false);
    }

    // ── Reviews and ratings ──────────────────────────────────────────────

    public function test_one_review_per_completed_visit(): void
    {
        $me = $this->client(['first_name' => 'Mia', 'last_name' => 'Reyes']);
        $uuid = $this->book($me);

        $this->postJson("/api/client/appointments/{$uuid}/review", ['rating' => 5])->assertStatus(422);

        $this->complete($uuid);
        $this->getJson("/api/client/appointments/{$uuid}")->assertJsonPath('data.can_review', true);

        $this->postJson("/api/client/appointments/{$uuid}/review", ['rating' => 4, 'comment' => 'Lovely'])
            ->assertCreated()
            ->assertJsonPath('data.rating', 4);
        $this->postJson("/api/client/appointments/{$uuid}/review", ['rating' => 5])->assertStatus(409);
        $this->postJson("/api/client/appointments/{$uuid}/review", ['rating' => 9])->assertStatus(422);

        $this->getJson('/api/client/reviews')->assertOk()->assertJsonPath('data.0.comment', 'Lovely');
        $this->getJson("/api/client/appointments/{$uuid}")->assertJsonPath('data.can_review', false)->assertJsonPath('data.review.rating', 4);

        $this->getJson("/api/spas/{$this->branch->uuid}/reviews")
            ->assertOk()
            ->assertJsonPath('meta.rating_avg', 4)
            ->assertJsonPath('meta.rating_count', 1)
            ->assertJsonPath('data.0.author', 'Mia R.');

        $this->getJson("/api/spas/{$this->branch->uuid}")->assertJsonPath('data.rating_count', 1);
        $this->getJson('/api/spas/nearby?lat=7.07&lng=125.61')->assertJsonPath('data.0.rating_avg', 4);
    }

    public function test_anonymous_reviews_hide_the_name(): void
    {
        $uuid = $this->book($this->client());
        $this->complete($uuid);

        $this->postJson("/api/client/appointments/{$uuid}/review", ['rating' => 3, 'is_anonymous' => true])->assertCreated();
        $this->getJson("/api/spas/{$this->branch->uuid}/reviews")->assertJsonPath('data.0.author', 'Anonymous');
    }

    // ── Favorites ────────────────────────────────────────────────────────

    public function test_favorites_are_idempotent_and_private(): void
    {
        $me = $this->client();
        Sanctum::actingAs($me);

        $this->putJson("/api/client/favorites/{$this->branch->uuid}")->assertOk()->assertJsonPath('data.is_favorite', true);
        $this->putJson("/api/client/favorites/{$this->branch->uuid}")->assertOk();

        $this->getJson('/api/client/favorites?lat=7.07&lng=125.61')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $this->branch->uuid)
            ->assertJsonPath('data.0.distance_km', 0);

        Sanctum::actingAs($this->client());
        $this->getJson('/api/client/favorites')->assertJsonCount(0, 'data');

        Sanctum::actingAs($me);
        $this->deleteJson("/api/client/favorites/{$this->branch->uuid}")->assertOk();
        $this->deleteJson("/api/client/favorites/{$this->branch->uuid}")->assertOk();
        $this->getJson('/api/client/favorites')->assertJsonCount(0, 'data');

        $this->putJson('/api/client/favorites/not-a-real-branch')->assertNotFound();
    }

    // ── Transactions ─────────────────────────────────────────────────────

    public function test_transactions_show_my_paid_visits(): void
    {
        $me = $this->client();
        $uuid = $this->book($me);
        $this->complete($uuid, 850);
        $this->complete($this->book($this->client()));

        Sanctum::actingAs($me);
        $this->getJson('/api/client/transactions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', 850)
            ->assertJsonPath('data.0.balance', 0)
            ->assertJsonPath('data.0.payments.0.method', 'GCash')
            ->assertJsonPath('data.0.appointment_uuid', $uuid);
    }

    // ── Notifications ────────────────────────────────────────────────────

    public function test_the_spa_changing_my_booking_notifies_me_but_my_own_cancel_does_not(): void
    {
        $me = $this->client();
        $uuid = $this->book($me);

        // Booking confirmation.
        $this->assertSame(1, Notification::where('user_id', $me->id)->count());

        app(AppointmentService::class)->checkIn($this->owner, $uuid);
        app(AppointmentService::class)->cancelAppointment($this->owner, $uuid, 'Therapist unwell');

        $titles = Notification::where('user_id', $me->id)->orderBy('id')->pluck('title')->all();
        $this->assertSame(['Booking confirmed', "You're checked in", 'Booking cancelled'], $titles);

        Sanctum::actingAs($me);
        $feed = $this->getJson('/api/client/notifications')->assertOk();
        $feed->assertJsonPath('meta.unread_count', 3)->assertJsonPath('data.0.appointment_uuid', $uuid);

        $id = $feed->json('data.0.id');
        $this->patchJson("/api/client/notifications/{$id}/read")->assertOk()->assertJsonPath('data.unread_count', 2);
        $this->postJson('/api/client/notifications/read-all')->assertOk();
        $this->getJson('/api/client/notifications')->assertJsonPath('meta.unread_count', 0);

        // Another client's notification can't be touched.
        Sanctum::actingAs($this->client());
        $this->patchJson("/api/client/notifications/{$id}/read")->assertNotFound();

        // A client cancelling their own booking gets no feed entry for it.
        $other = $this->client();
        $theirs = $this->book($other);
        $this->postJson("/api/client/appointments/{$theirs}/cancel")->assertOk();
        $this->assertSame(['Booking confirmed'], Notification::where('user_id', $other->id)->pluck('title')->all());
    }

    // ── Password ─────────────────────────────────────────────────────────

    public function test_change_password(): void
    {
        $me = $this->client();
        Sanctum::actingAs($me);

        $this->postJson('/api/client/change-password', [
            'current_password' => 'wrong',
            'password' => 'new-secret-1',
            'password_confirmation' => 'new-secret-1',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->postJson('/api/client/change-password', [
            'current_password' => 'secret-pass',
            'password' => 'new-secret-1',
            'password_confirmation' => 'new-secret-1',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secret-1', $me->fresh()->password));
    }

    public function test_client_routes_need_a_client_account(): void
    {
        $this->getJson('/api/client/appointments')->assertUnauthorized();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/client/appointments')->assertForbidden();
    }
}

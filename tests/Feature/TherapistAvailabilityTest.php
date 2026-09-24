<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\BranchService;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Service\Business\AppointmentService;
use App\Service\Business\SpaBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Covers the date/time-aware therapist availability the client booking flow
// runs on: the public GET /spas/{uuid}/therapists lookup its picker is
// filtered by, and the matching hard gate on POST /client/appointments that
// stops a stale list from booking someone who isn't free.
//
// The pairing is the point — the two must agree, or the app shows a
// therapist it can't actually book. Both are exercised through the service
// layer, the same way BranchDetailResourceTest and AppointmentServiceTest do.
//
// Needs the real database engine: several migrations use engine-specific raw
// SQL, so sqlite :memory: cannot build this schema. phpunit.xml points at a
// local Postgres `servora_test` database — never run this against a shared
// one, since RefreshDatabase drops every table.
//
//   php artisan test --filter=TherapistAvailabilityTest
class TherapistAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    // A Tuesday, comfortably in the future so the "choose a future time"
    // guard on client bookings never fires.
    private const DATE = '2027-03-02';
    private const DAY = 'Tuesday';

    private SpaBranchService $branchService;
    private AppointmentService $appointmentService;
    private SpaBusiness $business;
    private SpaBranch $branch;
    private Staff $therapist;
    private ServiceVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchService = app(SpaBranchService::class);
        $this->appointmentService = app(AppointmentService::class);

        $this->business = SpaBusiness::factory()->create();
        // publicFindByUuid 404s on anything not Verified + Active.
        $this->branch = SpaBranch::factory()->create([
            'spa_business_id' => $this->business->id,
            'verification_status' => 'Verified',
            'operating_status' => 'Active',
        ]);

        $this->therapist = Staff::factory()->create([
            'spa_branch_id' => $this->branch->id,
            'role' => 'therapist',
            'status' => 'active',
        ]);

        $service = Service::factory()->create(['spa_business_id' => $this->business->id]);
        $this->variant = ServiceVariant::factory()->create([
            'service_id' => $service->id,
            'duration_minutes' => 60,
        ]);
        BranchService::factory()->create([
            'spa_branch_id' => $this->branch->id,
            'service_variant_id' => $this->variant->id,
        ]);
    }

    /** @return array{available: bool, reason: ?string} the therapist's row */
    private function lookup(string $time = '10:00', int $duration = 60): array
    {
        $rows = $this->branchService
            ->publicTherapistAvailability($this->branch->uuid, [
                'date' => self::DATE,
                'time' => $time,
                'duration_minutes' => $duration,
            ])
            ->toArray(request());

        $this->assertCount(1, $rows, 'expected exactly the one seeded therapist');

        $row = $rows[0];
        $this->assertSame($this->therapist->uuid, $row['uuid']);

        return ['available' => $row['available'], 'reason' => $row['reason']];
    }

    private function schedule(array $attributes): StaffSchedule
    {
        return StaffSchedule::create(array_merge([
            'staff_id' => $this->therapist->id,
            'day_of_week' => self::DAY,
        ], $attributes));
    }

    /** An existing booking that occupies the therapist for the given window. */
    private function existingBooking(string $time): void
    {
        $appointment = Appointment::create([
            'spa_branch_id' => $this->branch->id,
            'client_id' => \App\Models\Client::factory()->create([
                'spa_business_id' => $this->business->id,
            ])->id,
            'appointment_number' => 'TEST-' . uniqid(),
            'appointment_date' => self::DATE,
            'appointment_time' => $time,
            'appointment_type' => 'Reservation',
            'source' => 'Front Desk',
            'status' => Appointment::STATUS_SCHEDULED,
        ]);

        $item = AppointmentServiceItem::create([
            'appointment_id' => $appointment->id,
            'service_variant_id' => $this->variant->id,
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'status' => 'Pending',
            'sort_order' => 1,
        ]);

        TherapistAssignment::create([
            'appointment_service_id' => $item->id,
            'staff_id' => $this->therapist->id,
            'assignment_status' => 'Assigned',
        ]);
    }

    // ── The public lookup ────────────────────────────────────────────────

    // The documented opt-in-if-configured stance: most branches won't have
    // shift data entered when this ships, and treating that as "nobody is
    // available" would empty the picker for all of them.
    public function test_therapist_with_no_schedule_rows_is_available(): void
    {
        $this->assertTrue($this->lookup()['available']);
    }

    public function test_therapist_inside_their_shift_is_available(): void
    {
        $this->schedule(['start_time' => '09:00:00', 'end_time' => '18:00:00']);

        $this->assertTrue($this->lookup('10:00')['available']);
    }

    public function test_therapist_on_a_day_off_is_unavailable(): void
    {
        $this->schedule(['is_day_off' => true]);

        $result = $this->lookup();

        $this->assertFalse($result['available']);
        $this->assertStringContainsString('day off', $result['reason']);
    }

    public function test_time_outside_the_shift_is_unavailable(): void
    {
        $this->schedule(['start_time' => '09:00:00', 'end_time' => '12:00:00']);

        // 11:30 + 60 min runs past the 12:00 end of the shift.
        $result = $this->lookup('11:30');

        $this->assertFalse($result['available']);
        $this->assertStringContainsString('not scheduled', $result['reason']);
    }

    public function test_time_straddling_the_break_is_unavailable(): void
    {
        $this->schedule([
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'break_start' => '12:00:00',
            'break_end' => '13:00:00',
        ]);

        // Starts before the break but runs into it.
        $this->assertFalse($this->lookup('11:30')['available']);
        // Clear of it on either side.
        $this->assertTrue($this->lookup('10:00')['available']);
        $this->assertTrue($this->lookup('13:00')['available']);
    }

    public function test_overlapping_assignment_makes_the_therapist_unavailable(): void
    {
        $this->existingBooking('10:00');

        $result = $this->lookup('10:30');

        $this->assertFalse($result['available']);
        $this->assertStringContainsString('already booked', $result['reason']);
    }

    public function test_a_non_overlapping_booking_leaves_the_therapist_available(): void
    {
        $this->existingBooking('10:00');

        // The 10:00 booking runs 60 minutes, so 11:00 is clear.
        $this->assertTrue($this->lookup('11:00')['available']);
    }

    // Schedule is checked before bookings so the more actionable reason wins:
    // "not working then" tells the client another slot might help, where
    // "already booked" invites them to wait it out.
    public function test_schedule_reason_wins_over_booked_reason(): void
    {
        $this->schedule(['is_day_off' => true]);
        $this->existingBooking('10:00');

        $this->assertStringContainsString('day off', $this->lookup('10:30')['reason']);
    }

    public function test_only_active_therapists_at_this_branch_are_listed(): void
    {
        Staff::factory()->create([
            'spa_branch_id' => $this->branch->id,
            'role' => 'therapist',
            'status' => 'inactive',
        ]);
        Staff::factory()->create([
            'spa_branch_id' => $this->branch->id,
            'role' => 'frontdesk',
            'status' => 'active',
        ]);

        // lookup() asserts a single row — the two above must not appear.
        $this->assertTrue($this->lookup()['available']);
    }

    // ── The booking gate ─────────────────────────────────────────────────

    private function client(): User
    {
        return User::factory()->create(['role' => 'client']);
    }

    private function bookingPayload(?string $therapistUuid): array
    {
        return array_filter([
            'spa_branch_uuid' => $this->branch->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => '10:00',
            'services' => [['service_variant_uuid' => $this->variant->uuid]],
            'requested_therapist_uuid' => $therapistUuid,
            'client' => [
                'first_name' => 'Ana',
                'last_name' => 'Cruz',
                'phone_number' => '09170000000',
            ],
        ], fn ($value) => $value !== null);
    }

    public function test_client_booking_is_rejected_for_an_off_schedule_therapist(): void
    {
        $this->schedule(['is_day_off' => true]);

        try {
            $this->appointmentService->createClientAppointment(
                $this->client(),
                $this->bookingPayload($this->therapist->uuid),
            );
            $this->fail('Expected a 422 for a therapist on their day off.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('day off', $e->getMessage());
        }

        // The abort happens inside the transaction, after the appointment row
        // and its services are written — nothing may survive it.
        $this->assertSame(0, Appointment::count());
        $this->assertSame(0, AppointmentServiceItem::count());
    }

    public function test_client_booking_is_rejected_when_the_therapist_is_already_booked(): void
    {
        $this->existingBooking('10:00');

        try {
            $this->appointmentService->createClientAppointment(
                $this->client(),
                $this->bookingPayload($this->therapist->uuid),
            );
            $this->fail('Expected a 422 for a double-booked therapist.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('already booked', $e->getMessage());
        }

        // Only the pre-existing booking remains.
        $this->assertSame(1, Appointment::count());
    }

    public function test_the_same_booking_succeeds_without_a_requested_therapist(): void
    {
        $this->schedule(['is_day_off' => true]);

        $result = $this->appointmentService->createClientAppointment(
            $this->client(),
            $this->bookingPayload(null),
        );

        $this->assertNotInstanceOf(JsonResponse::class, $result);
        $this->assertSame(1, Appointment::count());
    }

    public function test_an_available_therapist_books_cleanly(): void
    {
        $this->schedule(['start_time' => '09:00:00', 'end_time' => '18:00:00']);

        $result = $this->appointmentService->createClientAppointment(
            $this->client(),
            $this->bookingPayload($this->therapist->uuid),
        );

        $this->assertNotInstanceOf(JsonResponse::class, $result);
        $this->assertSame(1, Appointment::count());
        $this->assertSame(1, TherapistAssignment::where('staff_id', $this->therapist->id)->count());
    }

    // An unknown therapist is not a scheduling conflict — the branch can still
    // serve the booking with whoever it assigns, so this stays the warning it
    // has always been rather than becoming a 422.
    public function test_an_unknown_therapist_is_still_only_a_warning(): void
    {
        $result = $this->appointmentService->createClientAppointment(
            $this->client(),
            $this->bookingPayload((string) \Illuminate\Support\Str::uuid()),
        );

        $this->assertNotInstanceOf(JsonResponse::class, $result);
        $this->assertSame(1, Appointment::count());
    }

    // ── The days-off lookup ──────────────────────────────────────────────
    //
    // Feeds the booking calendar once a therapist is picked. It must agree
    // with staffMatchesSchedule(): a date it lists is one on which no start
    // time at all would be accepted.

    /** @return list<string> */
    private function daysOff(string $from = '2027-03-01', string $to = '2027-03-21'): array
    {
        return $this->branchService->publicTherapistDaysOff(
            $this->branch->uuid,
            $this->therapist->uuid,
            ['from' => $from, 'to' => $to],
        )['data']['days_off'];
    }

    public function test_no_schedule_rows_means_no_days_off(): void
    {
        $this->assertSame([], $this->daysOff());
    }

    public function test_a_weekly_day_off_lists_every_such_date_in_range(): void
    {
        $this->schedule(['day_of_week' => 'Sunday', 'is_day_off' => true]);
        $this->schedule(['start_time' => '09:00:00', 'end_time' => '18:00:00']);

        $this->assertSame(['2027-03-07', '2027-03-14', '2027-03-21'], $this->daysOff());
    }

    public function test_every_listed_day_off_is_one_the_booking_check_rejects(): void
    {
        $this->schedule(['day_of_week' => 'Sunday', 'is_day_off' => true]);

        foreach ($this->daysOff() as $date) {
            $this->assertFalse(
                app(\App\Service\Business\AppointmentAvailabilityService::class)
                    ->staffMatchesSchedule($this->therapist->id, $date, '10:00', 60)['ok'],
                "{$date} was listed as a day off but would be bookable",
            );
        }
    }

    public function test_effective_until_ends_the_day_off_partway_through_the_range(): void
    {
        $this->schedule([
            'day_of_week' => 'Sunday',
            'is_day_off' => true,
            'effective_until' => '2027-03-10',
        ]);

        $this->assertSame(['2027-03-07'], $this->daysOff());
    }

    public function test_a_working_shift_on_the_same_weekday_means_the_day_is_not_off(): void
    {
        $this->schedule(['day_of_week' => 'Sunday', 'is_day_off' => true]);
        $this->schedule([
            'day_of_week' => 'Sunday',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ]);

        $this->assertSame([], $this->daysOff());
    }

    public function test_days_off_404s_for_a_therapist_not_active_at_this_branch(): void
    {
        $inactive = Staff::factory()->create([
            'spa_branch_id' => $this->branch->id,
            'role' => 'therapist',
            'status' => 'inactive',
        ]);
        $elsewhere = Staff::factory()->create([
            'spa_branch_id' => SpaBranch::factory()->create([
                'spa_business_id' => $this->business->id,
            ])->id,
            'role' => 'therapist',
            'status' => 'active',
        ]);

        foreach ([$inactive, $elsewhere] as $staff) {
            $this->getJson("/api/spas/{$this->branch->uuid}/therapists/{$staff->uuid}/days-off"
                . '?from=2027-03-01&to=2027-03-21')
                ->assertNotFound();
        }
    }

    public function test_days_off_rejects_an_over_long_range(): void
    {
        $this->getJson("/api/spas/{$this->branch->uuid}/therapists/{$this->therapist->uuid}/days-off"
            . '?from=2027-03-01&to=2027-12-31')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson("/api/spas/{$this->branch->uuid}/therapists/{$this->therapist->uuid}/days-off"
            . '?from=2027-03-01&to=2027-04-30')
            ->assertOk()
            ->assertJsonPath('data.days_off', []);
    }
}

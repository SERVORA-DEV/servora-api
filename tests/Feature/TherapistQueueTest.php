<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\Attendance;
use App\Models\BranchService;
use App\Models\Client;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\StaffQualification;
use App\Models\User;
use App\Service\Business\AppointmentService;
use App\Service\Business\TherapistQueueCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

// Covers the therapist rotation: the fair-turn order a spa floor runs on —
// first to time in takes the next client, and finishing a service sends you
// to the back of the line.
//
// The pairing is the point, same as TherapistQualificationTest: the branch
// board (TherapistQueueCalculator) and the appointment picker
// (AppointmentService::therapistOptions) must agree on turn order, or the
// front desk sees two different answers to "whose turn is it?"
//
// Every test here pins wall-clock time with Carbon::setTestNow() before each
// state change, because the rotation IS the ordering of those timestamps —
// letting real time decide them would make the assertions meaningless.
//
// Needs MySQL. phpunit.xml points at sqlite :memory:, which cannot build this
// schema at all — several migrations use raw `ALTER TABLE ... MODIFY ... ENUM`,
// which is MySQL-only. Run against the existing test database instead:
//
//   DB_CONNECTION=mysql DB_DATABASE=servora_testing DB_URL= \
//     php artisan test --filter=TherapistQueueTest
class TherapistQueueTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2027-03-02';

    private TherapistQueueCalculator $calculator;
    private AppointmentService $appointmentService;
    private User $owner;
    private SpaBusiness $business;
    private SpaBranch $branch;
    private Client $client;
    private Staff $alice;
    private Staff $bruno;
    private Staff $carla;
    private Service $massage;
    private ServiceVariant $massageVariant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = app(TherapistQueueCalculator::class);
        $this->appointmentService = app(AppointmentService::class);

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);
        $this->client = Client::factory()->create(['spa_business_id' => $this->business->id]);

        $this->alice = $this->therapist('Alice');
        $this->bruno = $this->therapist('Bruno');
        $this->carla = $this->therapist('Carla');

        $this->massage = Service::factory()->create([
            'spa_business_id' => $this->business->id,
            'name' => 'Swedish Massage',
        ]);
        $this->massageVariant = ServiceVariant::factory()->create([
            'service_id' => $this->massage->id,
            'duration_minutes' => 60,
        ]);
        BranchService::factory()->create([
            'spa_branch_id' => $this->branch->id,
            'service_variant_id' => $this->massageVariant->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function therapist(string $firstName): Staff
    {
        return Staff::factory()->create([
            'spa_branch_id' => $this->branch->id,
            'first_name' => $firstName,
            'role' => 'therapist',
            'status' => 'active',
        ]);
    }

    private function timeIn(Staff $staff, string $time): void
    {
        Attendance::create([
            'staff_id' => $staff->id,
            'attendance_date' => self::DATE,
            'check_in_at' => self::DATE . " {$time}:00",
            'status' => 'Present',
        ]);
    }

    private function timeOut(Staff $staff, string $time): void
    {
        Attendance::where('staff_id', $staff->id)
            ->where('attendance_date', self::DATE)
            ->update(['check_out_at' => self::DATE . " {$time}:00"]);
    }

    /** @return array<int, string> therapist first names in turn order, on-floor only */
    private function queue(): array
    {
        return $this->calculator->forBranch($this->branch->id, self::DATE)
            ->filter(fn (array $row) => $row['in_queue'])
            ->pluck('name')
            ->map(fn (string $name) => explode(' ', $name)[0])
            ->all();
    }

    private function row(Staff $staff): array
    {
        return $this->calculator->forBranch($this->branch->id, self::DATE)
            ->firstWhere('uuid', $staff->uuid);
    }

    private function bookedService(): AppointmentServiceItem
    {
        $created = $this->appointmentService->createAppointment($this->owner, [
            'spa_branch_uuid' => $this->branch->uuid,
            'client_uuid' => $this->client->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => '10:00',
            'appointment_type' => 'Reservation',
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $created, 'createAppointment failed: ' . $this->msg($created));

        $appointment = Appointment::where('uuid', $created->resource->uuid)->firstOrFail();

        $added = $this->appointmentService->addService($this->owner, $appointment->uuid, [
            'service_variant_uuid' => $this->massageVariant->uuid,
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $added, 'addService failed: ' . $this->msg($added));

        return AppointmentServiceItem::where('appointment_id', $appointment->id)->latest('id')->firstOrFail();
    }

    /** Runs a full assign -> start -> complete cycle, with each step stamped at a pinned time. */
    private function performService(Staff $staff, string $startTime, string $endTime): void
    {
        $item = $this->bookedService();

        Carbon::setTestNow(Carbon::parse(self::DATE . " {$startTime}:00"));
        $assigned = $this->appointmentService->assignTherapist($this->owner, $item->uuid, ['staff_uuid' => $staff->uuid]);
        $this->assertNotInstanceOf(JsonResponse::class, $assigned, 'assignTherapist failed: ' . $this->msg($assigned));

        $started = $this->appointmentService->startService($this->owner, $item->uuid);
        $this->assertNotInstanceOf(JsonResponse::class, $started, 'startService failed: ' . $this->msg($started));

        Carbon::setTestNow(Carbon::parse(self::DATE . " {$endTime}:00"));
        $completed = $this->appointmentService->completeService($this->owner, $item->uuid);
        $this->assertNotInstanceOf(JsonResponse::class, $completed, 'completeService failed: ' . $this->msg($completed));

        Carbon::setTestNow();
    }

    /** @return array<int, array<string, mixed>> the picker's rows for a fresh booking of the massage */
    private function pickerOptions(AppointmentServiceItem $item): array
    {
        return $this->appointmentService
            ->therapistOptions($this->owner, $item->uuid)
            ->getData(true)['data'];
    }

    private function msg($result): string
    {
        return $result instanceof JsonResponse ? (string) ($result->getData(true)['message'] ?? '') : '';
    }

    // ── Turn order ───────────────────────────────────────────────────────

    public function test_the_queue_follows_the_order_therapists_timed_in(): void
    {
        $this->timeIn($this->carla, '08:00');
        $this->timeIn($this->alice, '09:00');
        $this->timeIn($this->bruno, '10:00');

        $this->assertSame(['Carla', 'Alice', 'Bruno'], $this->queue());
        $this->assertSame(1, $this->row($this->carla)['position']);
    }

    public function test_completing_a_service_moves_that_therapist_to_the_back(): void
    {
        $this->timeIn($this->alice, '08:00');
        $this->timeIn($this->bruno, '08:30');
        $this->timeIn($this->carla, '09:00');
        $this->assertSame(['Alice', 'Bruno', 'Carla'], $this->queue());

        $this->performService($this->alice, '09:30', '10:30');

        $this->assertSame(['Bruno', 'Carla', 'Alice'], $this->queue());
    }

    // The point of the "who joined the line more recently" framing: a therapist
    // who times in AFTER someone else finished is behind them, because that
    // person rejoined the back of the line before they even arrived.
    public function test_a_late_arrival_queues_behind_someone_who_already_finished(): void
    {
        $this->timeIn($this->alice, '08:00');
        $this->performService($this->alice, '08:30', '09:00');

        $this->timeIn($this->bruno, '09:30');

        $this->assertSame(['Alice', 'Bruno'], $this->queue());
    }

    // ── Holding position while occupied ──────────────────────────────────

    public function test_an_assigned_therapist_keeps_their_position_but_is_not_next_up(): void
    {
        $this->timeIn($this->alice, '08:00');
        $this->timeIn($this->bruno, '09:00');

        $item = $this->bookedService();
        $this->appointmentService->assignTherapist($this->owner, $item->uuid, ['staff_uuid' => $this->alice->uuid]);

        $rows = $this->calculator->forBranch($this->branch->id, self::DATE);

        $this->assertSame(1, $this->row($this->alice)['position'], 'Alice keeps her turn — she has not finished anything yet');
        $this->assertSame('assigned', $this->row($this->alice)['rotation_status']);
        $this->assertSame($this->bruno->uuid, $this->calculator->nextUp($rows)['uuid']);
    }

    public function test_a_therapist_in_service_keeps_their_position_but_is_not_next_up(): void
    {
        $this->timeIn($this->alice, '08:00');
        $this->timeIn($this->bruno, '09:00');

        $item = $this->bookedService();
        $this->appointmentService->assignTherapist($this->owner, $item->uuid, ['staff_uuid' => $this->alice->uuid]);
        $this->appointmentService->startService($this->owner, $item->uuid);

        $rows = $this->calculator->forBranch($this->branch->id, self::DATE);

        $this->assertSame(1, $this->row($this->alice)['position']);
        $this->assertSame('in_service', $this->row($this->alice)['rotation_status']);
        $this->assertSame($this->bruno->uuid, $this->calculator->nextUp($rows)['uuid']);
    }

    // A client who cancels must not cost their therapist a turn — which is the
    // whole reason the rotation moves on completion rather than on assignment.
    public function test_a_cancelled_assignment_leaves_the_therapist_where_they_were(): void
    {
        $this->timeIn($this->alice, '08:00');
        $this->timeIn($this->bruno, '09:00');

        $item = $this->bookedService();
        $this->appointmentService->assignTherapist($this->owner, $item->uuid, ['staff_uuid' => $this->alice->uuid]);
        $assignment = $item->fresh()->therapistAssignments->firstWhere('staff_id', $this->alice->id);
        $this->appointmentService->cancelAssignment($this->owner, $assignment->uuid);

        $rows = $this->calculator->forBranch($this->branch->id, self::DATE);

        $this->assertSame(['Alice', 'Bruno'], $this->queue());
        $this->assertSame('free', $this->row($this->alice)['rotation_status']);
        $this->assertSame($this->alice->uuid, $this->calculator->nextUp($rows)['uuid']);
    }

    // ── Who is on the floor ──────────────────────────────────────────────

    public function test_a_therapist_who_never_timed_in_is_not_in_the_queue(): void
    {
        $this->timeIn($this->alice, '08:00');

        $this->assertSame(['Alice'], $this->queue());
        $this->assertFalse($this->row($this->bruno)['in_queue']);
        $this->assertNull($this->row($this->bruno)['position']);
    }

    public function test_timing_out_removes_a_therapist_from_the_queue(): void
    {
        $this->timeIn($this->alice, '08:00');
        $this->timeIn($this->bruno, '09:00');
        $this->timeOut($this->alice, '12:00');

        $this->assertSame(['Bruno'], $this->queue());
        $this->assertFalse($this->row($this->alice)['in_queue']);
        $this->assertSame(1, $this->row($this->bruno)['position'], 'positions close up once someone leaves');
    }

    public function test_yesterdays_rotation_does_not_leak_into_todays(): void
    {
        $this->timeIn($this->alice, '08:00');

        $this->assertSame([], $this->calculator->forBranch($this->branch->id, '2027-03-01')
            ->filter(fn (array $row) => $row['in_queue'])
            ->all());
    }

    // ── The appointment picker ───────────────────────────────────────────

    public function test_the_picker_returns_therapists_in_rotation_order(): void
    {
        $this->timeIn($this->carla, '08:00');
        $this->timeIn($this->alice, '09:00');
        $this->timeIn($this->bruno, '10:00');

        $options = $this->pickerOptions($this->bookedService());

        $this->assertSame([1, 2, 3], array_column($options, 'queue_position'));
        $this->assertSame('Carla', explode(' ', $options[0]['name'])[0]);
        $this->assertTrue($options[0]['is_next_up']);
    }

    public function test_the_picker_sorts_therapists_who_are_off_the_floor_last(): void
    {
        $this->timeIn($this->bruno, '09:00');

        $options = $this->pickerOptions($this->bookedService());

        $this->assertTrue($options[0]['in_queue']);
        $this->assertSame('Bruno', explode(' ', $options[0]['name'])[0]);
        $this->assertFalse($options[1]['in_queue']);
        $this->assertNull($options[1]['queue_position']);
    }

    // "Next up" on the picker is narrower than the branch rotation: a #1 who
    // can't perform THIS service isn't actually next for this booking.
    public function test_next_up_on_the_picker_skips_a_therapist_unqualified_for_the_service(): void
    {
        $this->timeIn($this->alice, '08:00');
        $this->timeIn($this->bruno, '09:00');

        $other = Service::factory()->create(['spa_business_id' => $this->business->id, 'name' => 'Classic Facial']);
        StaffQualification::create(['staff_id' => $this->alice->id, 'service_id' => $other->id]);

        $options = $this->pickerOptions($this->bookedService());
        $nextUp = collect($options)->firstWhere('is_next_up', true);

        $this->assertSame(1, $this->row($this->alice)['position'], 'Alice is still #1 on the branch board');
        $this->assertSame($this->bruno->uuid, $nextUp['uuid'], 'but Bruno is next for this booking');
    }

    // The therapist already holding this service is filtered out of the picker
    // list by the frontend, so naming them next up would point at a row that
    // isn't on screen.
    public function test_next_up_skips_a_therapist_already_assigned_to_this_service(): void
    {
        $this->timeIn($this->alice, '08:00');
        $this->timeIn($this->bruno, '09:00');

        $item = $this->bookedService();
        $this->appointmentService->assignTherapist($this->owner, $item->uuid, ['staff_uuid' => $this->alice->uuid]);

        $nextUp = collect($this->pickerOptions($item))->firstWhere('is_next_up', true);

        $this->assertSame($this->bruno->uuid, $nextUp['uuid']);
    }

    // The same-therapist auto-continue in completeService() starts the client's
    // next service for whoever just finished. The rotation has to stay coherent
    // through that: they go to the back on the first completion and are shown
    // as busy, not as free-and-last.
    public function test_the_same_therapist_auto_continue_leaves_the_rotation_coherent(): void
    {
        $this->timeIn($this->alice, '08:00');
        $this->timeIn($this->bruno, '09:00');

        $first = $this->bookedService();
        $appointment = $first->appointment;

        $second = $this->appointmentService->addService($this->owner, $appointment->uuid, [
            'service_variant_uuid' => $this->massageVariant->uuid,
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $second, 'addService failed: ' . $this->msg($second));
        $secondItem = AppointmentServiceItem::where('appointment_id', $appointment->id)->latest('id')->firstOrFail();

        Carbon::setTestNow(Carbon::parse(self::DATE . ' 09:30:00'));
        $this->appointmentService->assignTherapist($this->owner, $first->uuid, ['staff_uuid' => $this->alice->uuid]);
        $this->appointmentService->assignTherapist($this->owner, $secondItem->uuid, ['staff_uuid' => $this->alice->uuid]);
        $this->appointmentService->startService($this->owner, $first->uuid);

        Carbon::setTestNow(Carbon::parse(self::DATE . ' 10:30:00'));
        $this->appointmentService->completeService($this->owner, $first->uuid);
        Carbon::setTestNow();

        $this->assertSame('In Progress', $secondItem->fresh()->status, 'auto-continue should have started the second service');
        $this->assertSame(['Bruno', 'Alice'], $this->queue(), 'Alice went to the back on her first completion');
        $this->assertSame('in_service', $this->row($this->alice)['rotation_status'], 'and is busy, not free-and-last');
    }
}

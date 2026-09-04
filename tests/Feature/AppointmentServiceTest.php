<?php

namespace Tests\Feature;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\BranchPackage;
use App\Models\BranchService;
use App\Models\Client;
use App\Models\Facility;
use App\Models\Package;
use App\Models\PackageServiceItem;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Repository\Business\AppointmentRepository;
use App\Service\Business\AppointmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

// Covers Part D (room-independent-of-staff assignment, reschedule guard) and
// the regression/verification items from the plan at
// C:\Users\jake2\.claude\plans\add-a-design-or-reactive-hammock.md — calls
// AppointmentService methods directly (bypassing HTTP/routing/permission
// middleware) since the business logic under test lives entirely in that
// service layer.
class AppointmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private AppointmentService $service;
    private User $owner;
    private SpaBusiness $business;
    private SpaBranch $branch;
    private Client $client;
    private Facility $facilityA;
    private Facility $facilityB;
    private Staff $therapistA;
    private Staff $therapistB;
    private ServiceVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AppointmentService::class);

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);
        $this->client = Client::factory()->create(['spa_business_id' => $this->business->id]);

        $svc = Service::factory()->create(['spa_business_id' => $this->business->id]);
        $this->variant = ServiceVariant::factory()->create(['service_id' => $svc->id, 'duration_minutes' => 60]);
        BranchService::factory()->create(['spa_branch_id' => $this->branch->id, 'service_variant_id' => $this->variant->id]);

        $this->facilityA = Facility::factory()->create(['spa_branch_id' => $this->branch->id]);
        $this->facilityB = Facility::factory()->create(['spa_branch_id' => $this->branch->id]);
        $this->therapistA = Staff::factory()->create(['spa_branch_id' => $this->branch->id]);
        $this->therapistB = Staff::factory()->create(['spa_branch_id' => $this->branch->id]);
    }

    private function createAppointment(string $date = '2026-09-01', string $time = '10:00'): Appointment
    {
        $result = $this->service->createAppointment($this->owner, [
            'spa_branch_uuid' => $this->branch->uuid,
            'client_uuid' => $this->client->uuid,
            'appointment_date' => $date,
            'appointment_time' => $time,
            'appointment_type' => 'Reservation',
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $result, 'createAppointment failed: ' . $this->msg($result));

        return Appointment::where('uuid', $result->resource->uuid)->firstOrFail();
    }

    private function addService(Appointment $appointment, ?ServiceVariant $variant = null): AppointmentServiceItem
    {
        $result = $this->service->addService($this->owner, $appointment->uuid, [
            'service_variant_uuid' => ($variant ?? $this->variant)->uuid,
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $result, 'addService failed: ' . $this->msg($result));

        return AppointmentServiceItem::where('appointment_id', $appointment->id)->latest('id')->firstOrFail();
    }

    private function msg($result): string
    {
        return $result instanceof JsonResponse ? (string) ($result->getData(true)['message'] ?? '') : '';
    }

    public function test_room_only_assignment_is_later_claimed_by_a_therapist_preserving_the_room(): void
    {
        $appointment = $this->createAppointment();
        $service = $this->addService($appointment);

        $roomResult = $this->service->assignTherapist($this->owner, $service->uuid, [
            'facility_uuid' => $this->facilityA->uuid,
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $roomResult, $this->msg($roomResult));

        $assignments = TherapistAssignment::where('appointment_service_id', $service->id)->get();
        $this->assertCount(1, $assignments);
        $this->assertNull($assignments->first()->staff_id);
        $this->assertSame($this->facilityA->id, $assignments->first()->facility_id);
        $roomOnlyId = $assignments->first()->id;

        $claimResult = $this->service->assignTherapist($this->owner, $service->uuid, [
            'staff_uuid' => $this->therapistA->uuid,
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $claimResult, $this->msg($claimResult));

        $assignments = TherapistAssignment::where('appointment_service_id', $service->id)->get();
        $this->assertCount(1, $assignments, 'claiming should reuse the room-only row, not create a second one');
        $claimed = $assignments->first();
        $this->assertSame($roomOnlyId, $claimed->id);
        $this->assertSame($this->therapistA->id, $claimed->staff_id);
        $this->assertSame($this->facilityA->id, $claimed->facility_id, 'room must be preserved after claiming');
    }

    public function test_assigning_service_package_and_room_never_flips_status_to_in_service(): void
    {
        $appointment = $this->createAppointment();
        $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->status);

        $service = $this->addService($appointment);
        $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->fresh()->status);

        $package = Package::factory()->create(['spa_business_id' => $this->business->id]);
        PackageServiceItem::create([
            'package_id' => $package->id,
            'service_variant_id' => $this->variant->id,
            'quantity' => 1,
            'sort_order' => 1,
        ]);
        BranchPackage::factory()->create(['spa_branch_id' => $this->branch->id, 'package_id' => $package->id]);

        $packageResult = $this->service->addPackage($this->owner, $appointment->uuid, ['package_uuid' => $package->uuid]);
        $this->assertNotInstanceOf(JsonResponse::class, $packageResult, $this->msg($packageResult));
        $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->fresh()->status);

        $roomResult = $this->service->assignTherapist($this->owner, $service->uuid, ['facility_uuid' => $this->facilityB->uuid]);
        $this->assertNotInstanceOf(JsonResponse::class, $roomResult, $this->msg($roomResult));
        $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->fresh()->status);

        $checkinResult = $this->service->checkIn($this->owner, $appointment->uuid);
        $this->assertNotInstanceOf(JsonResponse::class, $checkinResult, $this->msg($checkinResult));
        $this->assertSame(Appointment::STATUS_CHECKED_IN, $appointment->fresh()->status);

        $therapistResult = $this->service->assignTherapist($this->owner, $service->uuid, ['staff_uuid' => $this->therapistA->uuid]);
        $this->assertNotInstanceOf(JsonResponse::class, $therapistResult, $this->msg($therapistResult));
        $this->assertSame(Appointment::STATUS_CHECKED_IN, $appointment->fresh()->status, 'assigning a therapist must never itself start service');
    }

    public function test_same_appointment_can_reuse_the_same_therapist_and_room_across_services(): void
    {
        // Regression test: reservations are never time-blocked (see
        // assignTherapistInternal()/assignRoomInternal()), so assigning the
        // same therapist/room to a second service on this same appointment
        // must always succeed.
        $appointment = $this->createAppointment();
        $serviceA = $this->addService($appointment);
        $serviceB = $this->addService($appointment);

        $firstResult = $this->service->assignTherapist($this->owner, $serviceA->uuid, [
            'staff_uuid' => $this->therapistA->uuid,
            'facility_uuid' => $this->facilityA->uuid,
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $firstResult, $this->msg($firstResult));

        $secondResult = $this->service->assignTherapist($this->owner, $serviceB->uuid, [
            'staff_uuid' => $this->therapistA->uuid,
            'facility_uuid' => $this->facilityA->uuid,
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $secondResult, $this->msg($secondResult));

        $assignments = TherapistAssignment::whereIn('appointment_service_id', [$serviceA->id, $serviceB->id])->get();
        $this->assertCount(2, $assignments);
        $this->assertTrue($assignments->every(fn ($a) => $a->staff_id === $this->therapistA->id && $a->facility_id === $this->facilityA->id));
    }

    public function test_reschedule_succeeds_from_scheduled_and_checked_in_but_blocked_once_in_service(): void
    {
        $appointment = $this->createAppointment('2026-09-01', '10:00');
        $service = $this->addService($appointment);

        $result = $this->service->rescheduleAppointment($this->owner, $appointment->uuid, [
            'appointment_date' => '2026-09-02',
            'appointment_time' => '11:00',
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $result, $this->msg($result));
        $appointment->refresh();
        $this->assertSame('2026-09-02', $appointment->appointment_date->format('Y-m-d'));
        $this->assertStringStartsWith('11:00', $appointment->appointment_time);

        $this->service->checkIn($this->owner, $appointment->uuid);
        $result = $this->service->rescheduleAppointment($this->owner, $appointment->uuid, [
            'appointment_date' => '2026-09-02',
            'appointment_time' => '13:00',
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $result, $this->msg($result));

        $this->service->assignTherapist($this->owner, $service->uuid, ['staff_uuid' => $this->therapistA->uuid]);
        $startResult = $this->service->startService($this->owner, $service->uuid);
        $this->assertNotInstanceOf(JsonResponse::class, $startResult, $this->msg($startResult));
        $this->assertSame(Appointment::STATUS_IN_SERVICE, $appointment->fresh()->status);

        $blocked = $this->service->rescheduleAppointment($this->owner, $appointment->uuid, [
            'appointment_date' => '2026-09-03',
            'appointment_time' => '09:00',
        ]);
        $this->assertInstanceOf(JsonResponse::class, $blocked);
        $this->assertSame(422, $blocked->getStatusCode());
    }

    public function test_reschedule_succeeds_despite_a_projected_staff_or_room_overlap(): void
    {
        // Reservations were never time-blocked (walk-in/queue model, no
        // real per-service time slots) — rescheduling into a window that
        // *looks* like it overlaps another appointment's assigned
        // therapist/room must succeed regardless. The only real conflict
        // check left is the real-time one at startService(), unaffected
        // here since nothing has actually started.
        $blocker = $this->createAppointment('2026-09-05', '10:00');
        $blockerService = $this->addService($blocker);
        $this->service->assignTherapist($this->owner, $blockerService->uuid, [
            'staff_uuid' => $this->therapistA->uuid,
            'facility_uuid' => $this->facilityA->uuid,
        ]);

        $movable = $this->createAppointment('2026-09-05', '14:00');
        $movableService = $this->addService($movable);
        $this->service->assignTherapist($this->owner, $movableService->uuid, [
            'staff_uuid' => $this->therapistA->uuid,
            'facility_uuid' => $this->facilityA->uuid,
        ]);

        // Moving into the blocker's projected 10:00-11:00 window.
        $result = $this->service->rescheduleAppointment($this->owner, $movable->uuid, [
            'appointment_date' => '2026-09-05',
            'appointment_time' => '10:30',
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $result, $this->msg($result));
        $this->assertStringStartsWith('10:30', $movable->fresh()->appointment_time);
    }

    public function test_different_appointments_can_reserve_the_same_therapist_ahead_of_time(): void
    {
        // Therapist reservations stay unblocked (walk-in/queue model, no
        // real per-service time slots) — only rooms became a hard gate; see
        // test_overlapping_room_reservation_is_rejected_across_appointments
        // below for the room-specific behavior this used to also assert.
        $first = $this->createAppointment('2026-09-05', '10:00');
        $firstService = $this->addService($first);
        $firstResult = $this->service->assignTherapist($this->owner, $firstService->uuid, [
            'staff_uuid' => $this->therapistA->uuid,
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $firstResult, $this->msg($firstResult));

        // Second appointment, same day, at a time that would have
        // overlapped the first appointment's projected 60-minute window.
        $second = $this->createAppointment('2026-09-05', '10:30');
        $secondService = $this->addService($second);
        $secondResult = $this->service->assignTherapist($this->owner, $secondService->uuid, [
            'staff_uuid' => $this->therapistA->uuid,
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $secondResult, $this->msg($secondResult));
    }

    public function test_overlapping_room_reservation_is_rejected_across_appointments(): void
    {
        // Unlike therapists, a room IS a hard time-blocked resource now — a
        // physical room genuinely can't hold two appointments at once. See
        // AppointmentAvailabilityService::isFacilityAvailable().
        $first = $this->createAppointment('2026-09-06', '10:00');
        $firstService = $this->addService($first); // 60-minute variant: occupies 10:00-11:00
        $firstResult = $this->service->assignTherapist($this->owner, $firstService->uuid, [
            'facility_uuid' => $this->facilityA->uuid,
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $firstResult, $this->msg($firstResult));

        // Overlaps the first appointment's 10:00-11:00 window.
        $second = $this->createAppointment('2026-09-06', '10:30');
        $secondService = $this->addService($second);
        $secondResult = $this->service->assignTherapist($this->owner, $secondService->uuid, [
            'facility_uuid' => $this->facilityA->uuid,
        ]);
        $this->assertInstanceOf(JsonResponse::class, $secondResult);
        $this->assertSame(422, $secondResult->getStatusCode());

        // A non-overlapping time for the same room succeeds.
        $third = $this->createAppointment('2026-09-06', '12:00');
        $thirdService = $this->addService($third);
        $thirdResult = $this->service->assignTherapist($this->owner, $thirdService->uuid, [
            'facility_uuid' => $this->facilityA->uuid,
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $thirdResult, $this->msg($thirdResult));
    }

    public function test_add_service_still_succeeds_while_appointment_is_in_service(): void
    {
        $appointment = $this->createAppointment();
        $service = $this->addService($appointment);
        $this->service->checkIn($this->owner, $appointment->uuid);
        $this->service->assignTherapist($this->owner, $service->uuid, ['staff_uuid' => $this->therapistA->uuid]);
        $this->service->startService($this->owner, $service->uuid);
        $this->assertSame(Appointment::STATUS_IN_SERVICE, $appointment->fresh()->status);

        $result = $this->service->addService($this->owner, $appointment->uuid, [
            'service_variant_uuid' => $this->variant->uuid,
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $result, $this->msg($result));

        $pending = AppointmentServiceItem::where('appointment_id', $appointment->id)->where('status', 'Pending')->count();
        $this->assertSame(1, $pending);
    }

    public function test_total_duration_minutes_sums_non_cancelled_services_and_excludes_cancelled(): void
    {
        $appointment = $this->createAppointment();

        $variant30 = ServiceVariant::factory()->create(['service_id' => $this->variant->service_id, 'duration_minutes' => 30]);
        BranchService::factory()->create(['spa_branch_id' => $this->branch->id, 'service_variant_id' => $variant30->id]);

        $svcA = $this->addService($appointment); // 60 min
        $svcB = $this->addService($appointment, $variant30); // 30 min, kept
        $svcC = $this->addService($appointment, $variant30); // 30 min, cancelled below

        $this->service->removeService($this->owner, $svcC->uuid);

        $fresh = app(AppointmentRepository::class)->findByUuid($appointment->uuid);
        $resource = (new AppointmentResource($fresh))->toArray(new Request());

        $this->assertSame(90, $resource['total_duration_minutes']);
    }

    public function test_check_in_automatically_queues_the_appointment(): void
    {
        $appointment = $this->createAppointment('2026-09-01', '10:00');
        $this->assertNull($appointment->fresh()->queue);

        $result = $this->service->checkIn($this->owner, $appointment->uuid);
        $this->assertNotInstanceOf(JsonResponse::class, $result, $this->msg($result));

        $fresh = $appointment->fresh();
        $this->assertSame(Appointment::STATUS_CHECKED_IN, $fresh->status);
        $this->assertNotNull($fresh->queue);
        $this->assertSame('Waiting', $fresh->queue->queue_status);
        $this->assertNotEmpty($fresh->queue->queue_number);
    }

    public function test_walk_in_creation_checks_in_and_queues_immediately(): void
    {
        $result = $this->service->createAppointment($this->owner, [
            'spa_branch_uuid' => $this->branch->uuid,
            'client_uuid' => $this->client->uuid,
            'appointment_date' => '2026-09-01',
            'appointment_time' => '10:00',
            'appointment_type' => 'Walk-in',
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $result, $this->msg($result));

        $appointment = Appointment::where('uuid', $result->resource->uuid)->firstOrFail();
        $this->assertSame(Appointment::STATUS_CHECKED_IN, $appointment->status);
        $this->assertNotNull($appointment->check_in_at);
        $this->assertNotNull($appointment->queue);
        $this->assertSame('Waiting', $appointment->queue->queue_status);
    }

    public function test_reservation_creation_stays_scheduled_without_a_queue_entry(): void
    {
        $appointment = $this->createAppointment('2026-09-01', '10:00');

        $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->status);
        $this->assertNull($appointment->check_in_at);
        $this->assertNull($appointment->queue);
    }
}

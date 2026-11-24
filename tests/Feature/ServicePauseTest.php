<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AssignmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\Attendance;
use App\Models\BranchService;
use App\Models\Client;
use App\Models\Facility;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Service\Business\AppointmentAvailabilityService;
use App\Service\Business\AppointmentService;
use App\Service\Business\ServiceExecutionService;
use App\Service\Business\VisitAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

// Pausing a service in progress (the clock stops; therapist and room stay held), and handing it
// to another therapist while it is paused (App\Support\Visits\ServicePause,
// VisitAssignmentService::changeTherapist). The therapist who finishes is the one paid.
class ServicePauseTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2027-03-02';

    private AppointmentService $appointments;
    private ServiceExecutionService $execution;
    private VisitAssignmentService $assignments;
    private User $owner;
    private SpaBranch $branch;
    private Client $client;
    private Staff $alice;
    private Staff $bruno;
    private Staff $carla;
    private ServiceVariant $massage;
    private Facility $roomA;
    private Facility $roomB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appointments = app(AppointmentService::class);
        $this->execution = app(ServiceExecutionService::class);
        $this->assignments = app(VisitAssignmentService::class);

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $business->id]);
        $this->client = Client::factory()->create(['spa_business_id' => $business->id]);
        $this->alice = $this->therapist('Alice');
        $this->bruno = $this->therapist('Bruno');
        $this->carla = $this->therapist('Carla');

        $service = Service::factory()->create(['spa_business_id' => $business->id, 'name' => 'Swedish Massage']);
        $this->massage = ServiceVariant::factory()->create(['service_id' => $service->id, 'duration_minutes' => 60]);
        BranchService::factory()->create(['spa_branch_id' => $this->branch->id, 'service_variant_id' => $this->massage->id]);
        $this->roomA = Facility::factory()->create(['spa_branch_id' => $this->branch->id]);
        $this->roomB = Facility::factory()->create(['spa_branch_id' => $this->branch->id]);

        \App\Models\SpaBusinessSetting::updateOrCreate(['spa_business_id' => $business->id], ['booking_defaults' => ['max_advance_booking_days' => 0]]);
        \App\Service\Client\BookingPolicy::flush();

        $this->at('08:00');
        foreach ([$this->alice, $this->bruno] as $staff) {
            Attendance::create(['staff_id' => $staff->id, 'attendance_date' => self::DATE, 'check_in_at' => self::DATE.' 08:00:00', 'status' => 'Present']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse(self::DATE." {$time}:00"));
    }

    private function therapist(string $name): Staff
    {
        return Staff::factory()->create(['spa_branch_id' => $this->branch->id, 'first_name' => $name, 'role' => 'therapist', 'status' => 'active']);
    }

    private function ok($result, string $what)
    {
        $this->assertNotInstanceOf(JsonResponse::class, $result, "{$what} failed: ".($result instanceof JsonResponse ? ($result->getData(true)['message'] ?? '') : ''));

        return $result;
    }

    private function refused($result, string $contains): void
    {
        $this->assertInstanceOf(JsonResponse::class, $result, 'expected a refusal');
        $this->assertSame(422, $result->getStatusCode());
        $this->assertStringContainsString($contains, $result->getData(true)['message'] ?? '');
    }

    /** A massage with Alice in room A, started at 10:00. */
    private function running(?Staff $who = null, ?Facility $room = null, ?Client $client = null): AppointmentServiceItem
    {
        $this->at('08:30');
        $created = $this->ok($this->appointments->createAppointment($this->owner, [
            'spa_branch_uuid' => $this->branch->uuid,
            'client_uuid' => ($client ?? $this->client)->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => '10:00',
            'appointment_type' => 'Reservation',
        ]), 'createAppointment');
        $appointment = Appointment::where('uuid', $created->resource->uuid)->firstOrFail();
        $appointment->update(['status' => AppointmentStatus::CheckedIn->value, 'check_in_at' => now()]);
        $this->ok($this->appointments->addService($this->owner, $appointment->uuid, ['service_variant_uuid' => $this->massage->uuid]), 'addService');
        $line = AppointmentServiceItem::where('appointment_id', $appointment->id)->latest('id')->firstOrFail();

        $this->ok($this->appointments->assignTherapist($this->owner, $line->uuid, ['staff_uuid' => ($who ?? $this->alice)->uuid, 'facility_uuid' => ($room ?? $this->roomA)->uuid]), 'assignTherapist');
        $this->at('10:00');
        $this->ok($this->execution->startService($this->owner, $line->uuid), 'startService');

        return $line->fresh();
    }

    private function row(AppointmentServiceItem $line, Staff $staff): TherapistAssignment
    {
        return TherapistAssignment::where('appointment_service_id', $line->id)->where('staff_id', $staff->id)->firstOrFail();
    }

    // ── Pause ────────────────────────────────────────────────────────────

    public function test_a_pause_stops_the_clock_and_the_time_used_leaves_it_out(): void
    {
        $line = $this->running();

        $this->at('10:20');
        $this->ok($this->execution->pauseService($this->owner, $line->uuid, 'Restroom'), 'pause');
        $this->assertNotNull($line->fresh()->paused_at);
        $this->assertSame('In Progress', $line->fresh()->status, 'a paused service stays In Progress');

        $this->at('10:35');
        $this->ok($this->execution->resumeService($this->owner, $line->uuid), 'resume');
        $line->refresh();
        $this->assertNull($line->paused_at);
        $this->assertSame(15, $line->paused_minutes);
        // The clock goes on where it stopped: as if it had started 15 minutes later.
        $this->assertSame(self::DATE.' 10:15:00', $this->row($line, $this->alice)->started_at->format('Y-m-d H:i:s'));

        $this->at('11:15');
        $this->ok($this->execution->completeService($this->owner, $line->uuid), 'complete');
        $row = $this->row($line, $this->alice);
        $this->assertSame('Completed', $row->assignment_status);
        $this->assertSame(60, (int) $row->started_at->diffInMinutes($row->completed_at), 'one hour of service, the pause left out');
    }

    public function test_completing_while_paused_ends_the_pause_first(): void
    {
        $line = $this->running();
        $this->at('10:40');
        $this->ok($this->execution->pauseService($this->owner, $line->uuid), 'pause');
        $this->at('10:50');
        $this->ok($this->execution->completeService($this->owner, $line->uuid), 'complete');

        $line->refresh();
        $this->assertNull($line->paused_at);
        $this->assertSame(10, $line->paused_minutes);
        $row = $this->row($line, $this->alice);
        $this->assertSame(40, (int) $row->started_at->diffInMinutes($row->completed_at));
    }

    public function test_pause_and_resume_refuse_what_does_not_fit(): void
    {
        $line = $this->running();
        $this->refused($this->execution->resumeService($this->owner, $line->uuid), 'not paused');
        $this->ok($this->execution->pauseService($this->owner, $line->uuid), 'pause');
        $this->refused($this->execution->pauseService($this->owner, $line->uuid), 'already paused');

        $this->at('10:30');
        $this->ok($this->execution->resumeService($this->owner, $line->uuid), 'resume');
        $this->ok($this->execution->completeService($this->owner, $line->uuid), 'complete');
        $this->refused($this->execution->pauseService($this->owner, $line->uuid), 'Only a service in progress');
    }

    public function test_the_home_visit_page_is_told_when_it_was_paused(): void
    {
        $line = $this->running();
        $door = app(\App\Service\Business\HomeVisitDoorService::class);
        $this->assertNull($door->visit($line->appointment)['paused_at'], 'running: nothing paused');

        $this->at('10:20');
        $this->ok($this->execution->pauseService($this->owner, $line->uuid), 'pause');
        $page = $door->visit($line->appointment->fresh());
        // The page stops its countdown there.
        $this->assertSame(Carbon::parse(self::DATE.' 10:20:00')->toIso8601String(), $page['paused_at']);
        $this->assertSame($page['paused_at'], $page['services'][0]['paused_at']);

        $this->at('10:30');
        $this->ok($this->execution->resumeService($this->owner, $line->uuid), 'resume');
        $page = $door->visit($line->appointment->fresh());
        $this->assertNull($page['paused_at']);
        // Its start moved on by the pause, so the countdown carries on from where it stopped.
        $this->assertSame(Carbon::parse(self::DATE.' 11:10:00')->toIso8601String(), $page['ends_at']);
    }

    public function test_a_paused_service_with_no_facility_can_be_given_one(): void
    {
        $this->at('08:30');
        $created = $this->ok($this->appointments->createAppointment($this->owner, [
            'spa_branch_uuid' => $this->branch->uuid,
            'client_uuid' => $this->client->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => '10:00',
            'appointment_type' => 'Reservation',
        ]), 'createAppointment');
        $appointment = Appointment::where('uuid', $created->resource->uuid)->firstOrFail();
        $appointment->update(['status' => AppointmentStatus::CheckedIn->value, 'check_in_at' => now()]);
        $this->ok($this->appointments->addService($this->owner, $appointment->uuid, ['service_variant_uuid' => $this->massage->uuid]), 'addService');
        $line = AppointmentServiceItem::where('appointment_id', $appointment->id)->latest('id')->firstOrFail();
        $this->ok($this->appointments->assignTherapist($this->owner, $line->uuid, ['staff_uuid' => $this->alice->uuid]), 'assign Alice, no facility');
        $this->at('10:00');
        $this->ok($this->execution->startService($this->owner, $line->uuid), 'startService');
        $this->assertNull($this->row($line, $this->alice)->facility_id);

        $this->at('10:20');
        $this->ok($this->execution->pauseService($this->owner, $line->uuid), 'pause');
        $this->ok($this->assignments->assignRoom($this->owner, $this->row($line, $this->alice)->uuid, ['facility_uuid' => $this->roomB->uuid]), 'add a facility');
        $this->assertSame($this->roomB->id, $this->row($line, $this->alice)->facility_id);
        $this->assertSame(AssignmentStatus::InProgress->value, $this->row($line, $this->alice)->assignment_status, 'still under way');

        $this->at('10:30');
        $this->ok($this->execution->resumeService($this->owner, $line->uuid), 'resume');
        $this->assertNull($line->fresh()->paused_at);
    }

    public function test_a_paused_therapist_is_still_busy(): void
    {
        $line = $this->running();
        $this->ok($this->execution->pauseService($this->owner, $line->uuid), 'pause');

        $busy = app(AppointmentAvailabilityService::class)->isStaffCurrentlyBusy($this->alice->id);
        $this->assertFalse($busy['ok'], 'the therapist is held while the client steps out');
    }

    // ── Change of therapist ──────────────────────────────────────────────

    public function test_the_therapist_can_only_be_changed_while_paused(): void
    {
        $line = $this->running();
        $this->refused($this->assignments->changeTherapist($this->owner, $line->uuid, ['staff_uuid' => $this->bruno->uuid, 'reason' => 'Client preference']), 'Pause the service first');
    }

    public function test_the_one_who_finishes_takes_over_and_the_one_who_stopped_is_handed_over(): void
    {
        $line = $this->running();
        $this->at('10:20');
        $this->ok($this->execution->pauseService($this->owner, $line->uuid), 'pause');
        $this->ok($this->assignments->changeTherapist($this->owner, $line->uuid, ['staff_uuid' => $this->bruno->uuid, 'reason' => 'Client preference']), 'changeTherapist');

        $alice = $this->row($line, $this->alice);
        $this->assertSame(AssignmentStatus::HandedOver->value, $alice->assignment_status);
        $this->assertSame(self::DATE.' 10:20:00', $alice->completed_at->format('Y-m-d H:i:s'), 'she stopped when it was paused');
        $this->assertStringContainsString('Client preference', (string) $alice->remarks);
        $this->assertTrue(app(AppointmentAvailabilityService::class)->isStaffCurrentlyBusy($this->alice->id)['ok'], 'she is free at once');

        $bruno = $this->row($line, $this->bruno);
        $this->assertSame(AssignmentStatus::InProgress->value, $bruno->assignment_status);
        $this->assertSame($this->roomA->id, $bruno->facility_id, 'same room unless another is chosen');
        $this->assertFalse(app(AppointmentAvailabilityService::class)->isStaffCurrentlyBusy($this->bruno->id)['ok'], 'he is held from now');

        $this->at('10:30');
        $this->ok($this->execution->resumeService($this->owner, $line->uuid), 'resume');
        $this->at('11:10');
        $this->ok($this->execution->completeService($this->owner, $line->uuid), 'complete');

        $this->assertSame('Completed', $this->row($line, $this->bruno)->assignment_status);
        $this->assertSame(AssignmentStatus::HandedOver->value, $this->row($line, $this->alice)->assignment_status, 'only the one who finishes is completed, and paid');
        $this->assertSame('Completed', $line->fresh()->status);
    }

    public function test_the_service_can_move_to_another_room_with_the_new_therapist(): void
    {
        $line = $this->running();
        $this->ok($this->execution->pauseService($this->owner, $line->uuid), 'pause');
        $this->ok($this->assignments->changeTherapist($this->owner, $line->uuid, [
            'staff_uuid' => $this->bruno->uuid, 'reason' => 'Quieter room', 'facility_uuid' => $this->roomB->uuid,
        ]), 'changeTherapist');

        $this->assertSame($this->roomB->id, $this->row($line, $this->bruno)->facility_id);
    }

    public function test_only_someone_available_now_can_take_over(): void
    {
        $line = $this->running();
        $this->ok($this->execution->pauseService($this->owner, $line->uuid), 'pause');

        // Carla never timed in.
        $this->refused($this->assignments->changeTherapist($this->owner, $line->uuid, ['staff_uuid' => $this->carla->uuid, 'reason' => 'x']), "isn't timed in");
        // Alice is already doing it.
        $this->refused($this->assignments->changeTherapist($this->owner, $line->uuid, ['staff_uuid' => $this->alice->uuid, 'reason' => 'x']), 'already doing this service');

        // Bruno takes it; Alice can't take it back.
        $this->ok($this->assignments->changeTherapist($this->owner, $line->uuid, ['staff_uuid' => $this->bruno->uuid, 'reason' => 'x']), 'changeTherapist');
        $this->refused($this->assignments->changeTherapist($this->owner, $line->uuid, ['staff_uuid' => $this->alice->uuid, 'reason' => 'x']), 'already handed this service over');
    }

    public function test_a_paused_service_can_move_room_and_still_resume(): void
    {
        $line = $this->running();
        $this->at('10:20');
        $this->ok($this->execution->pauseService($this->owner, $line->uuid), 'pause');
        $row = $this->row($line, $this->alice);
        $this->ok($this->assignments->assignRoom($this->owner, $row->uuid, ['facility_uuid' => $this->roomB->uuid]), 'assignRoom');
        $this->assertSame($this->roomB->id, $row->fresh()->facility_id);
        $this->assertTrue(app(AppointmentAvailabilityService::class)->isFacilityCurrentlyOccupied($this->roomA->id)['ok'], 'the old room is free again');

        $this->at('10:30');
        $this->ok($this->execution->resumeService($this->owner, $line->uuid), 'resume');
        $this->assertNull($line->fresh()->paused_at);
        $this->assertSame(10, $line->fresh()->paused_minutes);
    }

    public function test_a_paused_service_done_by_two_staff_moves_room_with_both(): void
    {
        $back = \App\Models\ServicePart::create(['service_id' => $this->massage->service_id, 'name' => 'Back', 'share_percent' => 50, 'sort' => 1]);
        $legs = \App\Models\ServicePart::create(['service_id' => $this->massage->service_id, 'name' => 'Legs', 'share_percent' => 50, 'sort' => 2]);

        $this->at('08:30');
        $created = $this->ok($this->appointments->createAppointment($this->owner, [
            'spa_branch_uuid' => $this->branch->uuid,
            'client_uuid' => $this->client->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => '10:00',
            'appointment_type' => 'Reservation',
        ]), 'createAppointment');
        $appointment = Appointment::where('uuid', $created->resource->uuid)->firstOrFail();
        $appointment->update(['status' => AppointmentStatus::CheckedIn->value, 'check_in_at' => now()]);
        $this->ok($this->appointments->addService($this->owner, $appointment->uuid, ['service_variant_uuid' => $this->massage->uuid]), 'addService');
        $line = AppointmentServiceItem::where('appointment_id', $appointment->id)->latest('id')->firstOrFail();
        $this->ok($this->appointments->assignTherapist($this->owner, $line->uuid, ['staff_uuid' => $this->alice->uuid, 'facility_uuid' => $this->roomA->uuid, 'part_uuid' => $back->uuid]), 'assign Alice');
        $this->ok($this->appointments->assignTherapist($this->owner, $line->uuid, ['staff_uuid' => $this->bruno->uuid, 'facility_uuid' => $this->roomA->uuid, 'part_uuid' => $legs->uuid]), 'assign Bruno');
        $this->at('10:00');
        $this->ok($this->execution->startService($this->owner, $line->uuid), 'startService');

        $this->at('10:20');
        $this->ok($this->execution->pauseService($this->owner, $line->uuid), 'pause');
        $this->ok($this->assignments->assignRoom($this->owner, $this->row($line, $this->alice)->uuid, ['facility_uuid' => $this->roomB->uuid]), 'assignRoom');

        $this->assertSame($this->roomB->id, $this->row($line, $this->alice)->facility_id);
        $this->assertSame($this->roomB->id, $this->row($line, $this->bruno)->facility_id, 'the other staff on it move too');
        $this->assertTrue(app(AppointmentAvailabilityService::class)->isFacilityCurrentlyOccupied($this->roomA->id)['ok'], 'the old room is free again');
    }

    public function test_someone_held_for_a_reservation_soon_cannot_take_over(): void
    {
        $line = $this->running();
        // Bruno is booked for another client at 10:45, who hasn't arrived yet.
        $this->at('08:30');
        $other = Client::factory()->create(['spa_business_id' => $this->client->spa_business_id]);
        $created = $this->ok($this->appointments->createAppointment($this->owner, [
            'spa_branch_uuid' => $this->branch->uuid, 'client_uuid' => $other->uuid,
            'appointment_date' => self::DATE, 'appointment_time' => '10:45', 'appointment_type' => 'Reservation',
        ]), 'createAppointment');
        $booking = Appointment::where('uuid', $created->resource->uuid)->firstOrFail();
        $this->ok($this->appointments->addService($this->owner, $booking->uuid, ['service_variant_uuid' => $this->massage->uuid]), 'addService');
        $theirs = AppointmentServiceItem::where('appointment_id', $booking->id)->latest('id')->firstOrFail();
        $this->ok($this->appointments->assignTherapist($this->owner, $theirs->uuid, ['staff_uuid' => $this->bruno->uuid, 'facility_uuid' => $this->roomB->uuid]), 'assignTherapist');

        $this->at('10:20');
        $this->ok($this->execution->pauseService($this->owner, $line->uuid), 'pause');
        // 40 minutes of the massage are left: it would run into Bruno's 10:45 booking.
        $result = $this->assignments->changeTherapist($this->owner, $line->uuid, ['staff_uuid' => $this->bruno->uuid, 'reason' => 'x']);
        $this->assertInstanceOf(JsonResponse::class, $result, 'expected a refusal');
        $this->assertSame(422, $result->getStatusCode());
        $this->assertSame(AssignmentStatus::InProgress->value, $this->row($line, $this->alice)->assignment_status, 'nothing changed');
    }

    public function test_someone_busy_with_another_client_cannot_take_over(): void
    {
        $line = $this->running();
        // Bruno is in another client's massage.
        $other = $this->running($this->bruno, $this->roomB, Client::factory()->create(['spa_business_id' => $this->client->spa_business_id]));
        $this->assertNotSame($line->id, $other->id);
        $this->ok($this->execution->pauseService($this->owner, $line->uuid), 'pause');

        $this->refused($this->assignments->changeTherapist($this->owner, $line->uuid, ['staff_uuid' => $this->bruno->uuid, 'reason' => 'x']), 'currently in another service');
    }

    // ── A package: services one after another in one sitting ─────────────

    /** A 60-minute massage then a 30-minute facial with Alice in room A, started together at 10:00. */
    private function package(): array
    {
        $service = Service::factory()->create(['spa_business_id' => $this->client->spa_business_id, 'name' => 'Facial']);
        $facial = ServiceVariant::factory()->create(['service_id' => $service->id, 'duration_minutes' => 30]);
        BranchService::factory()->create(['spa_branch_id' => $this->branch->id, 'service_variant_id' => $facial->id]);

        $this->at('08:30');
        $created = $this->ok($this->appointments->createAppointment($this->owner, [
            'spa_branch_uuid' => $this->branch->uuid,
            'client_uuid' => $this->client->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => '10:00',
            'appointment_type' => 'Reservation',
        ]), 'createAppointment');
        $appointment = Appointment::where('uuid', $created->resource->uuid)->firstOrFail();
        $appointment->update(['status' => AppointmentStatus::CheckedIn->value, 'check_in_at' => now()]);
        $lines = [];
        foreach ([$this->massage, $facial] as $variant) {
            $this->ok($this->appointments->addService($this->owner, $appointment->uuid, ['service_variant_uuid' => $variant->uuid]), 'addService');
            $line = AppointmentServiceItem::where('appointment_id', $appointment->id)->latest('id')->firstOrFail();
            $this->ok($this->appointments->assignTherapist($this->owner, $line->uuid, ['staff_uuid' => $this->alice->uuid, 'facility_uuid' => $this->roomA->uuid]), 'assignTherapist');
            $lines[] = $line;
        }
        $this->at('10:00');
        $this->ok($this->execution->startService($this->owner, $lines[0]->uuid), 'start package');
        $this->assertSame('In Progress', $lines[1]->fresh()->status, 'the sitting starts as one');

        return [$lines[0]->fresh(), $lines[1]->fresh()];
    }

    public function test_a_package_pauses_and_resumes_as_one(): void
    {
        [$massage, $facial] = $this->package();
        $this->at('10:20');
        $this->ok($this->execution->pauseService($this->owner, $facial->uuid), 'pause');
        $this->assertNotNull($massage->fresh()->paused_at, 'pausing one service of the package pauses all of it');
        $this->assertNotNull($facial->fresh()->paused_at);

        $this->at('10:30');
        $this->ok($this->execution->resumeService($this->owner, $massage->uuid), 'resume');
        foreach ([$massage, $facial] as $line) {
            $this->assertNull($line->fresh()->paused_at);
            $this->assertSame(10, $line->fresh()->paused_minutes);
            $this->assertSame(self::DATE.' 10:10:00', $this->row($line, $this->alice)->started_at->format('Y-m-d H:i:s'));
        }
    }

    public function test_a_package_handed_over_pays_its_finished_services_to_the_one_who_did_them(): void
    {
        [$massage, $facial] = $this->package();
        // The massage (10:00–11:00) is done; the facial is 10 minutes in when the client steps out.
        $this->at('11:10');
        $this->ok($this->execution->pauseService($this->owner, $massage->uuid), 'pause');
        $this->at('11:12');
        $this->ok($this->assignments->changeTherapist($this->owner, $massage->uuid, ['staff_uuid' => $this->bruno->uuid, 'reason' => 'Therapist unwell']), 'changeTherapist');

        $this->assertSame('Completed', $massage->fresh()->status, 'the massage was already done');
        $this->assertNull($massage->fresh()->paused_at);
        $done = $this->row($massage, $this->alice);
        $this->assertSame(AssignmentStatus::Completed->value, $done->assignment_status, 'and is Alice\'s: she is paid for it');
        $this->assertSame(self::DATE.' 10:00:00', $done->started_at->format('Y-m-d H:i:s'));
        $this->assertSame(self::DATE.' 11:00:00', $done->completed_at->format('Y-m-d H:i:s'));
        $this->assertFalse(TherapistAssignment::where('appointment_service_id', $massage->id)->where('staff_id', $this->bruno->id)->exists(), 'Bruno has nothing of the massage');

        $handed = $this->row($facial, $this->alice);
        $this->assertSame(AssignmentStatus::HandedOver->value, $handed->assignment_status);
        $this->assertSame(self::DATE.' 11:00:00', $handed->started_at->format('Y-m-d H:i:s'), 'she began the facial when the massage ended');
        $this->assertSame(self::DATE.' 11:10:00', $handed->completed_at->format('Y-m-d H:i:s'));
        $carryOn = $this->row($facial, $this->bruno);
        $this->assertSame(AssignmentStatus::InProgress->value, $carryOn->assignment_status);
        $this->assertSame(self::DATE.' 11:00:00', $carryOn->started_at->format('Y-m-d H:i:s'), 'the facial\'s own clock, not the package\'s');

        $this->at('11:20');
        $this->ok($this->execution->resumeService($this->owner, $facial->uuid), 'resume');
        $this->assertSame(self::DATE.' 11:10:00', $this->row($facial, $this->bruno)->started_at->format('Y-m-d H:i:s'));
        $this->at('11:40');
        $this->ok($this->execution->completeService($this->owner, $facial->uuid), 'complete');
        $this->assertSame('Completed', $this->row($facial, $this->bruno)->assignment_status, 'Bruno finishes the facial and is paid for it');
        $this->assertSame(AssignmentStatus::HandedOver->value, $this->row($facial, $this->alice)->assignment_status);
    }

    public function test_a_package_handed_over_before_anything_is_done_goes_whole(): void
    {
        [$massage, $facial] = $this->package();
        $this->at('10:30');
        $this->ok($this->execution->pauseService($this->owner, $massage->uuid), 'pause');
        $this->ok($this->assignments->changeTherapist($this->owner, $massage->uuid, ['staff_uuid' => $this->bruno->uuid, 'reason' => 'Client preference']), 'changeTherapist');

        foreach ([$massage, $facial] as $line) {
            $this->assertSame('In Progress', $line->fresh()->status);
            $this->assertSame(AssignmentStatus::HandedOver->value, $this->row($line, $this->alice)->assignment_status);
            $this->assertSame(AssignmentStatus::InProgress->value, $this->row($line, $this->bruno)->assignment_status);
            $this->assertSame(self::DATE.' 10:00:00', $this->row($line, $this->bruno)->started_at->format('Y-m-d H:i:s'));
        }
    }

    public function test_a_paused_package_moves_room_as_one(): void
    {
        [$massage, $facial] = $this->package();
        $this->at('10:20');
        $this->ok($this->execution->pauseService($this->owner, $massage->uuid), 'pause');
        $this->ok($this->assignments->assignRoom($this->owner, $this->row($massage, $this->alice)->uuid, ['facility_uuid' => $this->roomB->uuid]), 'assignRoom');

        $this->assertSame($this->roomB->id, $this->row($massage, $this->alice)->facility_id);
        $this->assertSame($this->roomB->id, $this->row($facial, $this->alice)->facility_id, 'the rest of the package goes with it');
        $this->assertTrue(app(AppointmentAvailabilityService::class)->isFacilityCurrentlyOccupied($this->roomA->id)['ok'], 'the old room is free again');

        $this->at('10:30');
        $this->ok($this->execution->resumeService($this->owner, $facial->uuid), 'resume');
        $this->assertNull($massage->fresh()->paused_at);
        $this->assertNull($facial->fresh()->paused_at);
    }

    public function test_a_package_past_all_its_time_still_hands_over_its_last_service(): void
    {
        [$massage, $facial] = $this->package();
        // 10:00 + 90 minutes = 11:30; at 11:45 both are over time, but the client is still there.
        $this->at('11:45');
        $this->ok($this->execution->pauseService($this->owner, $massage->uuid), 'pause');
        $this->ok($this->assignments->changeTherapist($this->owner, $massage->uuid, ['staff_uuid' => $this->bruno->uuid, 'reason' => 'x']), 'changeTherapist');

        $this->assertSame('Completed', $massage->fresh()->status);
        $this->assertSame('In Progress', $facial->fresh()->status);
        $this->assertSame(AssignmentStatus::InProgress->value, $this->row($facial, $this->bruno)->assignment_status);
    }

    public function test_the_handed_over_therapist_goes_to_the_back_of_the_line(): void
    {
        Attendance::create(['staff_id' => $this->carla->id, 'attendance_date' => self::DATE, 'check_in_at' => self::DATE.' 08:00:00', 'status' => 'Present']);
        $line = $this->running();
        $other = $this->running($this->bruno, $this->roomB, Client::factory()->create(['spa_business_id' => $this->client->spa_business_id]));

        $this->at('10:20');
        $this->ok($this->execution->pauseService($this->owner, $line->uuid), 'pause');
        // Bruno finishes his client while Alice's is paused, then Alice hands hers to Carla.
        $this->at('10:30');
        $this->ok($this->execution->completeService($this->owner, $other->uuid), 'complete Bruno');
        $this->at('10:40');
        $this->ok($this->assignments->changeTherapist($this->owner, $line->uuid, ['staff_uuid' => $this->carla->uuid, 'reason' => 'Client preference']), 'changeTherapist');

        $line_up = app(\App\Service\Business\TherapistQueueCalculator::class)
            ->forBranch($this->branch->id, self::DATE)
            ->where('in_queue', true)
            ->pluck('staff_id')
            ->all();

        // Carla hasn't finished anyone yet (her turn counts from timing in); Bruno finished at 10:30;
        // Alice left her client at the hand-over, 10:40 — after Bruno, though she stopped work at 10:20.
        $this->assertSame([$this->carla->id, $this->bruno->id, $this->alice->id], $line_up);
    }
}


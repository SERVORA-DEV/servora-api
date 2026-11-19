<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\Billing;
use App\Models\SpaBranch;
use App\Models\User;

/**
 * The whole visit, from booking to payment, in one place to look: each step is handed to the class
 * that does it. New code calls those classes directly; this stays for the callers (and tests)
 * written against it.
 *
 *   AppointmentLookupService   reading visits and bills for the staff lists and pages
 *   AppointmentBookingService  booking, cancelling, moving, no-shows
 *   CheckInService             arriving, checking in, the line at the desk
 *   VisitItemsService          the services, packages and add-ons on a visit
 *   VisitAssignmentService     who does each service, and in which room
 *   ServiceExecutionService    starting and completing services
 *   HomeVisitService           home visits, from the door to payment
 *   VisitBillingService        billing a visit and taking payment
 *
 * Every change to a visit still goes through these classes, inside their row-locked
 * transactions (start, complete, bill), as it did when they were one class.
 */
class AppointmentService
{
    public function __construct(
        private AppointmentLookupService $lookup,
        private AppointmentBookingService $booking,
        private CheckInService $checkIns,
        private ServiceExecutionService $execution,
        private VisitItemsService $items,
        private VisitAssignmentService $assignments,
        private HomeVisitService $homeVisits,
        private VisitBillingService $visitBilling,
    ) {}

    public function listAppointments(User $user, array $filters, int $perPage = 15)
    {
        return $this->lookup->listAppointments($user, $filters, $perPage);
    }

    public function listBillings(User $user, array $filters, int $perPage = 15)
    {
        return $this->lookup->listBillings($user, $filters, $perPage);
    }

    public function getAppointment(User $user, string $uuid)
    {
        return $this->lookup->getAppointment($user, $uuid);
    }

    public function createAppointment(User $user, array $payload)
    {
        return $this->booking->createAppointment($user, $payload);
    }

    public function createClientAppointment(User $user, array $payload)
    {
        return $this->booking->createClientAppointment($user, $payload);
    }

    public function checkIn(User $user, string $uuid, bool $now = false, ?string $place = null, bool $pastClosing = false)
    {
        return $this->checkIns->checkIn($user, $uuid, $now, $place, $pastClosing);
    }

    public function markNoShow(User $user, string $uuid)
    {
        return $this->booking->markNoShow($user, $uuid);
    }

    public function recordNoShow(Appointment $appointment, bool $automatic = false): void
    {
        $this->booking->recordNoShow($appointment, $automatic);
    }

    public function closeStaleVisit(Appointment $appointment): void
    {
        $this->execution->closeStaleVisit($appointment);
    }

    public function noShowCount(int $clientId): int
    {
        return $this->booking->noShowCount($clientId);
    }

    public function clientBookingsOn(User $user, int $businessId, string $date, ?int $exceptAppointmentId = null): int
    {
        return $this->booking->clientBookingsOn($user, $businessId, $date, $exceptAppointmentId);
    }

    public static function dayLimitMessage(int $limit, string $date): string
    {
        return AppointmentBookingService::dayLimitMessage($limit, $date);
    }

    public function callQueue(User $user, string $uuid)
    {
        return $this->checkIns->callQueue($user, $uuid);
    }

    public function skipQueue(User $user, string $uuid, ?string $behind = null)
    {
        return $this->checkIns->skipQueue($user, $uuid, $behind);
    }

    public function recallQueue(User $user, string $uuid)
    {
        return $this->checkIns->recallQueue($user, $uuid);
    }

    public function cancelAppointment(User $user, string $uuid, ?string $reason = null)
    {
        return $this->booking->cancelAppointment($user, $uuid, $reason);
    }

    public function performCancel(Appointment $appointment, ?string $reason = null)
    {
        return $this->booking->performCancel($appointment, $reason);
    }

    public function rescheduleAppointment(User $user, string $uuid, array $payload)
    {
        return $this->booking->rescheduleAppointment($user, $uuid, $payload);
    }

    public function performReschedule(Appointment $appointment, array $payload)
    {
        return $this->booking->performReschedule($appointment, $payload);
    }

    public function addService(User $user, string $appointmentUuid, array $payload)
    {
        return $this->items->addService($user, $appointmentUuid, $payload);
    }

    public function removeService(User $user, string $appointmentServiceUuid)
    {
        return $this->items->removeService($user, $appointmentServiceUuid);
    }

    public function addAddon(User $user, string $appointmentServiceUuid, array $payload)
    {
        return $this->items->addAddon($user, $appointmentServiceUuid, $payload);
    }

    public function voidService(User $user, string $appointmentServiceUuid, array $payload)
    {
        return $this->items->voidService($user, $appointmentServiceUuid, $payload);
    }

    public function addPackage(User $user, string $appointmentUuid, array $payload)
    {
        return $this->items->addPackage($user, $appointmentUuid, $payload);
    }

    public function therapistOptions(User $user, string $appointmentServiceUuid)
    {
        return $this->assignments->therapistOptions($user, $appointmentServiceUuid);
    }

    public function facilityOptions(User $user, string $appointmentServiceUuid)
    {
        return $this->assignments->facilityOptions($user, $appointmentServiceUuid);
    }

    public function assignTherapist(User $user, string $appointmentServiceUuid, array $payload)
    {
        return $this->assignments->assignTherapist($user, $appointmentServiceUuid, $payload);
    }

    public function autoAssign(Appointment $appointment, string $mode = 'missing'): array
    {
        return $this->assignments->autoAssign($appointment, $mode);
    }

    public function autoAssignAppointment(User $user, string $appointmentUuid, string $mode = 'missing')
    {
        return $this->assignments->autoAssignAppointment($user, $appointmentUuid, $mode);
    }

    public function assignBatch(User $user, string $appointmentUuid, array $items)
    {
        return $this->assignments->assignBatch($user, $appointmentUuid, $items);
    }

    public function cancelAssignment(User $user, string $assignmentUuid)
    {
        return $this->assignments->cancelAssignment($user, $assignmentUuid);
    }

    public function assignRoom(User $user, string $assignmentUuid, array $payload)
    {
        return $this->assignments->assignRoom($user, $assignmentUuid, $payload);
    }

    public function clearRoom(User $user, string $assignmentUuid)
    {
        return $this->assignments->clearRoom($user, $assignmentUuid);
    }

    public function startService(User $user, string $appointmentServiceUuid)
    {
        return $this->execution->startService($user, $appointmentServiceUuid);
    }

    public function startHomeVisitByCode(SpaBranch $branch, string $code): array
    {
        return $this->homeVisits->startHomeVisitByCode($branch, $code);
    }

    public function startHomeVisitAtDesk(User $user, string $uuid)
    {
        return $this->homeVisits->startHomeVisitAtDesk($user, $uuid);
    }

    public function completeService(User $user, string $appointmentServiceUuid)
    {
        return $this->execution->completeService($user, $appointmentServiceUuid);
    }

    public function proceedToBilling(User $user, string $uuid)
    {
        return $this->visitBilling->proceedToBilling($user, $uuid);
    }

    public function getBilling(User $user, string $billingUuid)
    {
        return $this->lookup->getBilling($user, $billingUuid);
    }

    public function recordPayment(User $user, string $billingUuid, array $payload)
    {
        return $this->visitBilling->recordPayment($user, $billingUuid, $payload);
    }

    public function startHomeVisitAtDoor(Appointment $appointment, SpaBranch $branch): array
    {
        return $this->homeVisits->startHomeVisitAtDoor($appointment, $branch);
    }

    public function completeHomeVisitServices(Appointment $appointment): void
    {
        $this->homeVisits->completeHomeVisitServices($appointment);
    }

    public function billHomeVisit(Appointment $appointment): Billing
    {
        return $this->homeVisits->billHomeVisit($appointment);
    }

    public function payHomeVisit(Appointment $appointment, Billing $billing, array $payload, string $by): array
    {
        return $this->homeVisits->payHomeVisit($appointment, $billing, $payload, $by);
    }
}

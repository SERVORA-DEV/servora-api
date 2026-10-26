<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\AppointmentPackageRequest;
use App\Http\Requests\Business\AppointmentRequest;
use App\Http\Requests\Business\AppointmentServiceRequest;
use App\Http\Requests\Business\CancelAppointmentRequest;
use App\Http\Requests\Business\RescheduleAppointmentRequest;
use App\Service\Business\AppointmentService;
use App\Service\Business\DayCloser;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    private AppointmentService $appointmentService;

    public function __construct(AppointmentService $appointmentService, private DayCloser $dayCloser)
    {
        $this->appointmentService = $appointmentService;
    }

    // Visits from days that are over and were never closed (App\Support\StaleVisit),
    // and the desk asking for them to be closed now rather than at the next sweep.
    public function pastVisits(Request $request)
    {
        return response()->json(['data' => ['open' => $this->dayCloser->openCountForUser($request->user())]]);
    }

    public function closePastVisits(Request $request)
    {
        $done = $this->dayCloser->sweepForUser($request->user(), force: true) ?? ['no_shows' => 0, 'finished' => 0, 'released' => 0, 'vouchers' => 0];

        return response()->json(['data' => $done + ['open' => $this->dayCloser->openCountForUser($request->user())]]);
    }

    public function index(Request $request)
    {
        $filters = $request->only(['date', 'date_from', 'date_to', 'status', 'client_uuid', 'staff_uuid']);
        return $this->appointmentService->listAppointments($request->user(), $filters, $request->input('per_page', 15));
    }

    public function store(AppointmentRequest $request)
    {
        return $this->appointmentService->createAppointment($request->user(), $request->validated());
    }

    public function show(Request $request, string $uuid)
    {
        return $this->appointmentService->getAppointment($request->user(), $uuid);
    }

    public function checkIn(Request $request, string $uuid)
    {
        // `now`: a booking for a later day, served today instead — moved to now
        // and checked in at the back of the line.
        // `place`: where an early or late reservation joins the line — now,
        // last (both: from now) or at its booked time; `allow_past_closing`:
        // take a visit that would run past closing anyway. Asked for by the
        // 409 `check_in_choice` answer (AppointmentService::checkIn).
        $data = $request->validate([
            'now' => ['sometimes', 'boolean'],
            'place' => ['sometimes', 'nullable', 'in:now,booked,last'],
            'allow_past_closing' => ['sometimes', 'boolean'],
        ]);

        return $this->appointmentService->checkIn($request->user(), $uuid, (bool) ($data['now'] ?? false),
            $data['place'] ?? null, (bool) ($data['allow_past_closing'] ?? false));
    }

    public function cancel(CancelAppointmentRequest $request, string $uuid)
    {
        return $this->appointmentService->cancelAppointment($request->user(), $uuid, $request->validated()['cancellation_reason'] ?? null);
    }

    public function markNoShow(Request $request, string $uuid)
    {
        return $this->appointmentService->markNoShow($request->user(), $uuid);
    }

    public function callQueue(Request $request, string $uuid)
    {
        return $this->appointmentService->callQueue($request->user(), $uuid);
    }

    public function skipQueue(Request $request, string $uuid)
    {
        return $this->appointmentService->skipQueue($request->user(), $uuid);
    }

    public function recallQueue(Request $request, string $uuid)
    {
        return $this->appointmentService->recallQueue($request->user(), $uuid);
    }

    public function reschedule(RescheduleAppointmentRequest $request, string $uuid)
    {
        return $this->appointmentService->rescheduleAppointment($request->user(), $uuid, $request->validated());
    }

    public function addService(AppointmentServiceRequest $request, string $uuid)
    {
        return $this->appointmentService->addService($request->user(), $uuid, $request->validated());
    }

    public function removeService(Request $request, string $uuid)
    {
        return $this->appointmentService->removeService($request->user(), $uuid);
    }

    // A service that has started or finished, taken off the visit — always
    // with a reason (kept on the line).
    public function voidService(Request $request, string $uuid)
    {
        $payload = $request->validate(['reason' => 'required|string|min:3|max:255']);

        return $this->appointmentService->voidService($request->user(), $uuid, $payload);
    }

    public function addPackage(AppointmentPackageRequest $request, string $uuid)
    {
        return $this->appointmentService->addPackage($request->user(), $uuid, $request->validated());
    }

    public function proceedToBilling(Request $request, string $uuid)
    {
        return $this->appointmentService->proceedToBilling($request->user(), $uuid);
    }
}

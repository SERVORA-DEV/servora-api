<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\AppointmentDayCountsRequest;
use App\Http\Requests\Business\AppointmentListRequest;
use App\Http\Requests\Business\AppointmentPackageRequest;
use App\Http\Requests\Business\AppointmentRequest;
use App\Http\Requests\Business\AppointmentServiceRequest;
use App\Http\Requests\Business\AppointmentSummaryRequest;
use App\Http\Requests\Business\CancelAppointmentRequest;
use App\Http\Requests\Business\CheckInRequest;
use App\Http\Requests\Business\RescheduleAppointmentRequest;
use App\Http\Requests\Business\SkipQueueRequest;
use App\Http\Requests\Business\VoidServiceRequest;
use App\Service\Business\AppointmentBookingService;
use App\Service\Business\AppointmentLookupService;
use App\Service\Business\AppointmentOverviewService;
use App\Service\Business\CheckInService;
use App\Service\Business\DayCloser;
use App\Service\Business\HomeVisitService;
use App\Service\Business\VisitBillingService;
use App\Service\Business\VisitItemsService;
use App\Support\PerPage;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{

    public function __construct(
        private AppointmentLookupService $lookup,
        private AppointmentBookingService $booking,
        private CheckInService $checkIns,
        private VisitItemsService $items,
        private HomeVisitService $homeVisits,
        private VisitBillingService $visitBilling,
        private DayCloser $dayCloser,
        private AppointmentOverviewService $overview,
    ) {}

    // Visits from days that are over and were never closed (App\Support\Visits\StaleVisit),
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

    // One page of visits, filtered by the API (AppointmentListQuery says which filters).
    public function index(AppointmentListRequest $request)
    {
        $filters = $request->only(['date', 'date_from', 'date_to', 'status', 'list_status', 'type', 'therapist', 'service', 'search', 'client_uuid', 'staff_uuid', 'branch_uuid', 'view', 'sort']);
        $filters['with_counts'] = $request->boolean('with_counts');
        $filters['unbilled'] = $request->boolean('unbilled');

        return $this->lookup->listAppointments($request->user(), $filters, PerPage::from($request));
    }

    // The figures above the owner's list: the period's headline numbers (and the period
    // before), its day-by-day trend, and the staff and services to filter by.
    public function summary(AppointmentSummaryRequest $request)
    {
        $data = $request->validated();
        if ($data['date_to'] < $data['date_from']) {
            return response()->json(['message' => 'The end date is before the start date.'], 422);
        }
        $ids = $this->overview->branchIds($request->user(), $data['branch_uuid'] ?? null);
        if ($ids === null) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        return response()->json(['data' => $this->overview->summary($ids, $data['date_from'], $data['date_to'])]);
    }

    // One branch's visits per day over a stretch of days (its month calendar).
    public function dayCounts(AppointmentDayCountsRequest $request)
    {
        $data = $request->validated();
        // A calendar's worth (six weeks shown), not a year.
        $span = (strtotime($data['date_to']) - strtotime($data['date_from'])) / 86400;
        if ($span < 0 || $span > 62) {
            return response()->json(['message' => 'Ask for up to 62 days, the end not before the start.'], 422);
        }
        $ids = $this->overview->branchIds($request->user(), $data['branch_uuid']);
        if ($ids === null) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        return response()->json(['data' => (object) $this->overview->dayCounts($ids, $data['date_from'], $data['date_to'])]);
    }

    public function store(AppointmentRequest $request)
    {
        return $this->booking->createAppointment($request->user(), $request->validated());
    }

    public function show(Request $request, string $uuid)
    {
        return $this->lookup->getAppointment($request->user(), $uuid);
    }

    public function checkIn(CheckInRequest $request, string $uuid)
    {
        $data = $request->validated();

        return $this->checkIns->checkIn($request->user(), $uuid, (bool) ($data['now'] ?? false),
            $data['place'] ?? null, (bool) ($data['allow_past_closing'] ?? false));
    }

    // A home visit started at the desk when the client's QR can't be scanned:
    // what the scan does, with no queue number (HomeVisitService::startHomeVisitAtDesk).
    public function startHomeVisit(Request $request, string $uuid)
    {
        return $this->homeVisits->startHomeVisitAtDesk($request->user(), $uuid);
    }

    public function cancel(CancelAppointmentRequest $request, string $uuid)
    {
        return $this->booking->cancelAppointment($request->user(), $uuid, $request->validated()['cancellation_reason'] ?? null);
    }

    public function markNoShow(Request $request, string $uuid)
    {
        return $this->booking->markNoShow($request->user(), $uuid);
    }

    public function callQueue(Request $request, string $uuid)
    {
        return $this->checkIns->callQueue($request->user(), $uuid);
    }

    public function skipQueue(SkipQueueRequest $request, string $uuid)
    {
        $data = $request->validated();

        return $this->checkIns->skipQueue($request->user(), $uuid, $data['behind'] ?? null);
    }

    public function recallQueue(Request $request, string $uuid)
    {
        return $this->checkIns->recallQueue($request->user(), $uuid);
    }

    public function reschedule(RescheduleAppointmentRequest $request, string $uuid)
    {
        return $this->booking->rescheduleAppointment($request->user(), $uuid, $request->validated());
    }

    public function addService(AppointmentServiceRequest $request, string $uuid)
    {
        return $this->items->addService($request->user(), $uuid, $request->validated());
    }

    public function removeService(Request $request, string $uuid)
    {
        return $this->items->removeService($request->user(), $uuid);
    }

    // A service that has started or finished, taken off the visit — always
    // with a reason (kept on the line).
    public function voidService(VoidServiceRequest $request, string $uuid)
    {
        $payload = $request->validated();

        return $this->items->voidService($request->user(), $uuid, $payload);
    }

    public function addPackage(AppointmentPackageRequest $request, string $uuid)
    {
        return $this->items->addPackage($request->user(), $uuid, $request->validated());
    }

    public function proceedToBilling(Request $request, string $uuid)
    {
        return $this->visitBilling->proceedToBilling($request->user(), $uuid);
    }
}

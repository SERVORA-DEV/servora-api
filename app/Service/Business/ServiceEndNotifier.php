<?php

namespace App\Service\Business;

use App\Models\Addon;
use App\Models\AppointmentServiceItem;
use App\Models\Notification;
use App\Models\Service;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Support\History;
use App\Support\ServiceEndNotice;
use App\Support\ServiceKind;
use App\Support\StaleVisit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Tells the front desk (and the branch's manager) when a service that is in
// progress has run its time — the moment to check on it, mark it complete
// and free the room. One notice per service, in the bell, opening the visit.
//
// "Run its time" is the service's own start plus its own length; a visit's
// other services don't come into it — except its add-ons, which ride with it:
// their lengths are added to it, and they get no notice of their own
// (App\Support\ServiceAddon). Procedure-based services, whose time is
// an estimate, are left out. Nothing is changed on the visit: this
// only says so. Switched by Settings → Notifications (on_service_end).
//
// Called from two places, so it doesn't depend on either alone:
//  - every minute by the scheduler (appointments:notify-service-ends);
//  - whenever a front desk or manager's bell asks for news
//    (Business\NotificationController::index), for their own branch — which
//    is what makes it work where no scheduler is running, and within the
//    bell's own 20 seconds where one is.
// Sending twice is prevented by looking for the notice itself (same title,
// same visit, sent since the time it was due), so the two can't double up —
// and a service that was extended is announced again when its new time is up,
// because the earlier notice is from before that.
//
// The bell asks often, so the usual answer has to be cheap: one small query
// for what is in progress, and nothing more unless something is due.
class ServiceEndNotifier
{
    public function __construct(
        private StaffNotifier $notifier,
        private SpaBusinessRepository $spaBusinessRepository,
    ) {
    }

    /** For the branches this person works at. Never lets a failure reach the caller. */
    public function sweepForUser(User $user): int
    {
        if (! in_array($user->role, ['front_officer', 'manager', 'business_owner'], true)) {
            return 0;
        }

        try {
            $branchIds = $this->spaBusinessRepository->branchIdsForUser($user);

            return $branchIds ? $this->sweep($branchIds) : 0;
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * @param  ?int[]  $branchIds  null = every branch (the scheduler)
     * @return int  how many notices went out
     */
    public function sweep(?array $branchIds = null): int
    {
        $now = now();

        // The length (and kind) are the line's own copy of what was booked
        // (App\Support\History) — editing the menu mid-service moves nothing.
        $own = History::ready();
        $length = $own ? 'coalesce(s.duration_minutes, v.duration_minutes)' : 'v.duration_minutes';

        // What its add-ons add to it (0 until add-ons exist).
        // An add-on line keeps its minutes itself (it is not a service length).
        $addons = Addon::ready();
        $added = $addons
            ? "(select coalesce(sum(c.duration_minutes), 0) from appointment_services c where c.parent_appointment_service_id = s.id and c.status != 'Cancelled')"
            : '0';

        // Every service in progress: when it started and how long it is.
        $due = DB::table('therapist_assignments as ta')
            ->join('appointment_services as s', 's.id', '=', 'ta.appointment_service_id')
            ->join('service_variants as v', 'v.id', '=', 's.service_variant_id')
            ->join('appointments as a', 'a.id', '=', 's.appointment_id')
            ->where('ta.assignment_status', 'In Progress')
            ->whereNotNull('ta.started_at')
            ->where('s.status', 'In Progress')
            ->whereNull('a.deleted_at')
            // Not one left running from a day that is over: DayCloser finishes those, there is nothing to check on.
            ->where('a.appointment_date', '>=', StaleVisit::today())
            ->when($branchIds !== null, fn ($q) => $q->whereIn('a.spa_branch_id', $branchIds))
            // An add-on's time is counted on its main service, above.
            ->when($addons, fn ($q) => $q->whereNull('s.parent_appointment_service_id'))
            // A procedure-based service's time is only an estimate: there
            // is no moment it is "up" (App\Support\ServiceKind).
            ->when(Service::hasTypes(), fn ($q) => $q
                ->join('services as sv', 'sv.id', '=', 'v.service_id')
                ->whereRaw(($own ? 'coalesce(s.service_type, sv.service_type)' : 'sv.service_type') . ' != ?', [ServiceKind::PROCEDURE]))
            ->groupBy('s.id', 'a.uuid', DB::raw($length))
            ->get(['s.id as service_id', 'a.uuid as appointment_uuid', DB::raw("{$length} as duration_minutes"), DB::raw("{$added} as added_minutes"), DB::raw('min(ta.started_at) as started_at')])
            ->map(function ($row) use ($now) {
                $startedAt = Carbon::parse($row->started_at);
                $dueAt = ServiceEndNotice::dueAt($startedAt, (int) $row->duration_minutes + (int) $row->added_minutes);

                return $dueAt && $now->gte($dueAt)
                    ? ['service_id' => (int) $row->service_id, 'appointment_uuid' => $row->appointment_uuid, 'started_at' => $startedAt, 'due_at' => $dueAt]
                    : null;
            })
            ->filter()
            ->values();

        if ($due->isEmpty()) {
            return 0;
        }

        // The notices already out for these visits, in one look.
        $already = Notification::query()
            ->whereIn('reference_uuid', $due->pluck('appointment_uuid')->unique()->all())
            ->where('title', 'like', ServiceEndNotice::TITLE_PREFIX . '%')
            ->where('created_at', '>=', $due->min('due_at'))
            ->get(['title', 'reference_uuid', 'created_at']);

        $services = AppointmentServiceItem::query()
            ->whereIn('id', $due->pluck('service_id')->all())
            ->with('serviceVariant.service')
            ->get()
            ->keyBy('id');

        $sent = 0;
        foreach ($due as $row) {
            $service = $services->get($row['service_id']);
            $name = $service?->displayName() ?? 'Service';
            $title = ServiceEndNotice::title($name);

            $told = $already->contains(fn (Notification $n) => $n->title === $title
                && $n->reference_uuid === $row['appointment_uuid']
                && $n->created_at->gte($row['due_at']));
            if ($told || ! $service) {
                continue;
            }

            // Only now, for the rare one that is due and not yet said: who and where.
            $service->load([
                'appointment.client',
                'appointment.branch.business.settings',
                'therapistAssignments' => fn ($q) => $q->where('assignment_status', 'In Progress')->with('staff:id,first_name,last_name', 'facility:id,name'),
            ]);
            $appointment = $service->appointment;
            if (! $appointment?->branch) {
                continue;
            }

            $client = trim(($appointment->client?->first_name ?? '') . ' ' . ($appointment->client?->last_name ?? '')) ?: 'A client';
            $therapists = $service->therapistAssignments
                ->map(fn ($a) => $a->staff ? trim("{$a->staff->first_name} {$a->staff->last_name}") : null)
                ->filter()->unique()->values()->all();
            $room = $service->therapistAssignments->first(fn ($a) => $a->facility)?->facility?->name;

            // Floor operations: the desk and the manager, not the owner's feed.
            $this->notifier->branch(
                $appointment->branch,
                $title,
                ServiceEndNotice::message($name, $client, $row['due_at'], $therapists, $room),
                type: 'Appointment',
                includeOwner: false,
                preference: 'on_service_end',
                reference: $appointment->uuid,
            );
            $sent++;
        }

        return $sent;
    }
}

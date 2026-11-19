<?php

namespace App\Service\Business;

use App\Http\Resources\AppointmentListResource;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\BillingListResource;
use App\Http\Resources\BillingResource;
use App\Models\AppointmentServiceItem;
use App\Models\Billing;
use App\Models\User;
use App\Repository\BillingRepository;
use App\Repository\Business\AppointmentRepository;
use App\Repository\SpaBusinessRepository;
use App\Support\Catalog\SharedPackages;

/**
 * Reading visits and their bills for the staff lists and pages: one page of visits, one visit,
 * one page of bills, one bill. Nothing here changes anything.
 *
 * Split out of AppointmentService (which still hands every call on, for its older callers).
 */
class AppointmentLookupService
{
    public function __construct(
        private AppointmentRepository $appointmentRepository,
        private SpaBusinessRepository $spaBusinessRepository,
        private BillingRepository $billingRepository,
    ) {}

    // ── Listing / detail ────────────────────────────────────────────────

    /**
     * One page of the caller's visits. `view=list`: slim rows for a table (AppointmentListResource);
     * `with_counts`: the status dropdown's counts too (`status_counts`); `branch_uuid`: one branch.
     */
    public function listAppointments(User $user, array $filters, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $overview = app(AppointmentOverviewService::class);
        $branchIds = $overview->branchIds($user, $filters['branch_uuid'] ?? null) ?? [];
        $list = ($filters['view'] ?? null) === 'list';
        $collection = $this->appointmentRepository->paginateForBranches($branchIds, $filters, $perPage, $list, $filters['sort'] ?? 'date');

        if ($list) {
            $rows = AppointmentListResource::collection($collection);

            return empty($filters['with_counts']) ? $rows : $rows->additional(['status_counts' => $overview->statusCounts($branchIds, $filters)]);
        }
        // The assigned staff's leave / absence / lateness for the whole page at once.
        AssignmentWarningService::instance()->preload($collection->items());
        // Covered by another client's shared package: who paid, for the whole page in one query.
        // The desk doesn't ask such a client to pay for it (AppointmentResource `covered_by`).
        $covered = SharedPackages::coveredByFor(collect($collection->items())->pluck('id'));
        foreach ($collection->items() as $appointment) {
            $appointment->setAttribute('covered_by', $covered[$appointment->id] ?? null);
        }

        return AppointmentResource::collection($collection);
    }

    // Backs the owner/manager "Billing & Payments" page — every appointment
    // billing across the caller's branches (not the subscription billings
    // System > Transactions already covers). See BillingListResource for
    // the flattened shape this returns.
    public function listBillings(User $user, array $filters, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // One branch when asked (only one of the caller's own).
        $branchIds = app(AppointmentOverviewService::class)->branchIds($user, $filters['branch_uuid'] ?? null) ?? [];
        $collection = $this->billingRepository->paginateAppointmentBillingsForBranches($branchIds, $filters, $perPage);
        $rows = BillingListResource::collection($collection);

        // The status choices' counts and what the matching bills add up to (all of them, not the page).
        $base = fn (bool $withStatus) => \App\Repository\BillingListQuery::apply(
            \App\Models\Billing::query()->whereIn('billings.spa_branch_id', $branchIds)->where('billings.billing_type', 'Appointment'),
            $filters, $withStatus,
        );
        $extra = [];
        if (! empty($filters['with_counts'])) $extra['status_counts'] = \App\Repository\BillingListQuery::statusCounts($base(false));
        if (! empty($filters['with_totals'])) $extra['totals'] = \App\Repository\BillingListQuery::totals($base(true));

        return $extra ? $rows->additional($extra) : $rows;
    }

    public function getAppointment(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));

        return new AppointmentResource($appointment);
    }

    public function getBilling(User $user, string $billingUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // Full appointment context (client, branch, itemized services +
        // their therapist assignments) so the Billing & Payments detail
        // page can render Appointment Details / Bill Summary without a
        // second round trip to GET appointment/{uuid}.
        $billing = Billing::with([
            'payments.receiver',
            'appointment.client',
            'appointment.branch',
            'appointment.services.serviceVariant.service',
            'appointment.services.therapistAssignments.staff',
            'appointment.services.therapistAssignments.facility',
            // A package is charged as its own line, with its services under
            // it — without these the bill page showed the total but not the
            // package it was for.
            'appointment.services.sourceAppointmentPackage.package',
            'appointment.packages.package',
        ])
            ->where('uuid', $billingUuid)
            ->whereIn('spa_branch_id', $this->branchIds($user))
            ->firstOrFail();

        return new BillingResource($billing);
    }

    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchIdsForUser($user);
    }

    // An add-on is never handled as a service of its own: it has no therapist,
    // room, Start or Complete — its main service's are its own.
    private function addonFollows(AppointmentServiceItem $service, string $what)
    {
        return $service->isAddon()
            ? response()->json(['message' => "An add-on {$what} with its main service."], 422)
            : null;
    }

    // time | procedure | facility — a service saved before types existed is
    // time-based (App\Support\Catalog\ServiceKind).
    private function kindOf(AppointmentServiceItem $service): string
    {
        return $service->kind();
    }
}

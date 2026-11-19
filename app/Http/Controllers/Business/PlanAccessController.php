<?php

namespace App\Http\Controllers\Business;

use App\Enums\AppointmentStatus;
use App\Enums\AssignmentStatus;
use App\Enums\BillingStatus;
use App\Exceptions\ActionRefused;
use App\Exceptions\NotFound;
use App\Exceptions\NotPermitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\ExportAuthorizeRequest;
use App\Http\Requests\Business\ExportRequest;
use App\Models\Appointment;
use App\Models\Billing;
use App\Models\SpaBranch;
use App\Repository\SpaBusinessRepository;
use App\Service\Subscription\PlanAccessService;
use App\Support\Access\AccountScope;
use App\Support\AppCache;
use App\Support\Platform\PlanTerms;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PlanAccessController extends Controller
{
    public function __construct(private SpaBusinessRepository $businesses) {}

    public function show(Request $request, PlanAccessService $access)
    {
        $business = $this->businesses->findForUser($request->user());
        throw_unless($business, NotFound::class);

        return ['data' => $access->present($business->id)];
    }

    public function authorizeExport(ExportAuthorizeRequest $request)
    {
        $data = $request->validated();
        $business = $this->businesses->findForUser($request->user());
        $plan = $business ? AppCache::activeSubscription($business->id)?->plan : null;
        throw_unless($plan && in_array($data['format'], PlanTerms::exportFormats($plan), true), NotPermitted::class, 'Your plan does not include this export format.');
        // Restricted exports must use the server-built, date-filtered Reports export.
        throw_if($plan->export_days !== null, NotPermitted::class, "Your plan exports the last {$plan->export_days} days, from Reports.");

        return ['allowed' => true];
    }

    // Export data is built from scoped server records, never a client-supplied report.
    public function export(ExportRequest $request)
    {
        $data = $request->validated();
        $filter = $data['list_filter'] ?? null;
        $business = $this->businesses->findForUser($request->user());
        throw_unless($business, NotFound::class);
        $plan = AppCache::activeSubscription($business->id)?->plan;
        throw_unless($plan && in_array($data['format'], PlanTerms::exportFormats($plan), true), NotPermitted::class, 'Your plan does not include this export format.');
        $today = now('Asia/Manila')->startOfDay();
        if ($plan->export_days !== null) {
            throw_if(Carbon::parse($data['date_from'])->lt($today->copy()->subDays($plan->export_days - 1)) || Carbon::parse($data['date_to'])->gt($today), ActionRefused::class, "Your plan exports only the last {$plan->export_days} days. Choose dates in that period.");
        }
        $branches = SpaBranch::where('spa_business_id', $business->id);
        if ($request->user()->role !== 'business_owner') {
            $branches->whereIn('id', AccountScope::branchIds($request->user(), 'management'));
        }
        if (! empty($data['branch_uuid'])) {
            $branches->where('uuid', $data['branch_uuid']);
        }
        $ids = $branches->pluck('id');
        throw_if($ids->isEmpty(), NotPermitted::class, 'No accessible branch selected.');
        $from = $data['date_from'];
        $to = $data['date_to'];
        $visits = Appointment::with(['client', 'branch', 'services.therapistAssignments.staff'])
            ->whereIn('spa_branch_id', $ids)->whereBetween('appointment_date', [$from, $to])->orderBy('appointment_date')->get();
        $type = $data['type'];
        $columns = [];
        $rows = [];
        if ($type === 'sales') {
            $columns = ['Bill', 'Issued', 'Branch', 'Amount billed', 'Status'];
            $rows = Billing::with('branch')->whereIn('spa_branch_id', $ids)->where('billing_type', 'Appointment')
                ->whereBetween('issued_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
                ->when($filter === 'paid', fn ($q) => $q->where('status', BillingStatus::Paid->value))
                ->when($filter === 'refunded', fn ($q) => $q->where('status', BillingStatus::Refunded->value))
                ->when($filter === 'unpaid', fn ($q) => $q->whereNotIn('status', [BillingStatus::Paid->value, BillingStatus::Refunded->value, BillingStatus::Cancelled->value]))
                ->orderBy('issued_at')->get()
                ->map(fn ($b) => [$b->billing_number, $b->issued_at?->format('Y-m-d'), $b->branch?->branch_name, (float) $b->amount, $b->status])->all();
        } elseif ($type === 'appointments') {
            $columns = ['Booking', 'Date', 'Client', 'Branch', 'Source', 'Status', 'Amount booked'];
            $rows = $visits
                ->filter(fn ($a) => $filter === 'walkin' ? $a->appointment_type === 'Walk-in' : ($filter === 'booking' ? $a->appointment_type !== 'Walk-in' : true))
                ->map(fn ($a) => [$a->appointment_number, $a->appointment_date->format('Y-m-d'), trim($a->client?->first_name.' '.$a->client?->last_name), $a->branch?->branch_name, $a->source, $a->status, (float) $a->total_amount])->all();
        } elseif ($type === 'clients') {
            $columns = ['Client', 'Visits', 'Completed', 'Amount booked'];
            $rows = $visits->groupBy('client_id')
                ->filter(function ($v) use ($filter) {
                    $client = $v->first()->client;
                    if ($filter === 'app') return (bool) $client?->usesApp();
                    if ($filter === 'member') return (bool) $client?->activeMembership()->exists();
                    return true;
                })
                ->map(fn ($v) => [trim($v->first()->client?->first_name.' '.$v->first()->client?->last_name), $v->count(), $v->where('status', AppointmentStatus::Completed->value)->count(), (float) $v->sum('total_amount')])->values()->all();
        } elseif ($type === 'branches') {
            $columns = ['Branch', 'Visits', 'Completed', 'Amount booked'];
            $rows = $visits->groupBy('spa_branch_id')->map(fn ($v) => [$v->first()->branch?->branch_name, $v->count(), $v->where('status', AppointmentStatus::Completed->value)->count(), (float) $v->sum('total_amount')])->values()->all();
        } elseif ($type === 'services') {
            $columns = ['Service', 'Quantity', 'Amount booked', 'Refunded'];
            $rows = $visits->flatMap->services
                ->filter(fn ($l) => $filter === 'package' ? $l->source_appointment_package_id !== null
                    : ($filter === 'service' ? $l->source_appointment_package_id === null && $l->addon_id === null : true))
                ->groupBy('service_name')->map(fn ($v, $name) => [$name, (int) $v->sum('quantity'), (float) $v->sum('subtotal'), (float) $v->sum('refunded_amount')])->values()->all();
        } else {
            $columns = ['Staff', 'Service assignments', 'Completed assignments'];
            $rows = $visits->flatMap->services->flatMap->therapistAssignments->filter(fn ($a) => $a->staff_id !== null)->groupBy('staff_id')
                ->map(fn ($v) => [trim($v->first()->staff?->first_name.' '.$v->first()->staff?->last_name), $v->count(), $v->where('assignment_status', AssignmentStatus::Completed->value)->count()])->values()->all();
        }

        // The search box: rows with any cell containing the words typed.
        if ($q = mb_strtolower(trim($data['search'] ?? ''))) {
            $rows = array_values(array_filter($rows, fn ($row) => collect($row)->contains(fn ($cell) => str_contains(mb_strtolower((string) $cell), $q))));
        }
        $rows = array_values($rows);

        return ['data' => [
            'title' => ucfirst($type).' report', 'file' => "$type-$from-$to", 'period' => "$from to $to",
            'tables' => [['title' => ucfirst($type), 'columns' => array_map(fn ($label) => ['label' => $label], $columns), 'rows' => $rows]],
        ]];
    }
}

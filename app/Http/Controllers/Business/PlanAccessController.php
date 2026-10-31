<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Billing;
use App\Models\SpaBranch;
use App\Repository\SpaBusinessRepository;
use App\Service\PlanAccessService;
use App\Support\AccountScope;
use App\Support\AppCache;
use App\Support\PlanTerms;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PlanAccessController extends Controller
{
    public function __construct(private SpaBusinessRepository $businesses) {}

    public function show(Request $request, PlanAccessService $access)
    {
        $business = $this->businesses->findForUser($request->user());
        abort_unless($business, 404);

        return ['data' => $access->present($business->id)];
    }

    public function authorizeExport(Request $request)
    {
        $data = $request->validate(['format' => 'required|in:csv,pdf,excel']);
        $business = $this->businesses->findForUser($request->user());
        $plan = $business ? AppCache::activeSubscription($business->id)?->plan : null;
        abort_unless($plan && in_array($data['format'], PlanTerms::exportFormats($plan), true), 403, 'Your plan does not include this export format.');
        // Restricted exports must use the server-built, date-filtered Reports export.
        abort_if($plan->export_days !== null, 403, 'Use Reports to export CSV for the last 30 days.');

        return ['allowed' => true];
    }

    // Export data is built from scoped server records, never a client-supplied report.
    public function export(Request $request)
    {
        $data = $request->validate([
            'format' => 'required|in:csv,pdf,excel',
            'type' => 'required|in:sales,appointments,clients,services,staff,branches',
            'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'branch_uuid' => 'nullable|uuid',
        ]);
        $business = $this->businesses->findForUser($request->user());
        abort_unless($business, 404);
        $plan = AppCache::activeSubscription($business->id)?->plan;
        abort_unless($plan && in_array($data['format'], PlanTerms::exportFormats($plan), true), 403, 'Your plan does not include this export format.');
        $today = now('Asia/Manila')->startOfDay();
        if ($plan->export_days !== null) {
            abort_if(Carbon::parse($data['date_from'])->lt($today->copy()->subDays($plan->export_days - 1)) || Carbon::parse($data['date_to'])->gt($today), 422, "Your plan exports only the last {$plan->export_days} days. Choose dates in that period.");
        }
        $branches = SpaBranch::where('spa_business_id', $business->id);
        if ($request->user()->role !== 'business_owner') {
            $branches->whereIn('id', AccountScope::branchIds($request->user(), 'management'));
        }
        if (! empty($data['branch_uuid'])) {
            $branches->where('uuid', $data['branch_uuid']);
        }
        $ids = $branches->pluck('id');
        abort_if($ids->isEmpty(), 403, 'No accessible branch selected.');
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
                ->whereBetween('issued_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('issued_at')->get()
                ->map(fn ($b) => [$b->billing_number, $b->issued_at?->format('Y-m-d'), $b->branch?->branch_name, (float) $b->amount, $b->status])->all();
        } elseif ($type === 'appointments') {
            $columns = ['Booking', 'Date', 'Client', 'Branch', 'Source', 'Status', 'Amount booked'];
            $rows = $visits->map(fn ($a) => [$a->appointment_number, $a->appointment_date->format('Y-m-d'), trim($a->client?->first_name.' '.$a->client?->last_name), $a->branch?->branch_name, $a->source, $a->status, (float) $a->total_amount])->all();
        } elseif ($type === 'clients') {
            $columns = ['Client', 'Visits', 'Completed', 'Amount booked'];
            $rows = $visits->groupBy('client_id')->map(fn ($v) => [trim($v->first()->client?->first_name.' '.$v->first()->client?->last_name), $v->count(), $v->where('status', 'completed')->count(), (float) $v->sum('total_amount')])->values()->all();
        } elseif ($type === 'branches') {
            $columns = ['Branch', 'Visits', 'Completed', 'Amount booked'];
            $rows = $visits->groupBy('spa_branch_id')->map(fn ($v) => [$v->first()->branch?->branch_name, $v->count(), $v->where('status', 'completed')->count(), (float) $v->sum('total_amount')])->values()->all();
        } elseif ($type === 'services') {
            $columns = ['Service', 'Quantity', 'Amount booked', 'Refunded'];
            $rows = $visits->flatMap->services->groupBy('service_name')->map(fn ($v, $name) => [$name, (int) $v->sum('quantity'), (float) $v->sum('subtotal'), (float) $v->sum('refunded_amount')])->values()->all();
        } else {
            $columns = ['Staff', 'Service assignments', 'Completed assignments'];
            $rows = $visits->flatMap->services->flatMap->therapistAssignments->filter(fn ($a) => $a->staff_id !== null)->groupBy('staff_id')
                ->map(fn ($v) => [trim($v->first()->staff?->first_name.' '.$v->first()->staff?->last_name), $v->count(), $v->where('assignment_status', 'Completed')->count()])->values()->all();
        }

        return ['data' => [
            'title' => ucfirst($type).' report', 'file' => "$type-$from-$to", 'period' => "$from to $to",
            'tables' => [['title' => ucfirst($type), 'columns' => array_map(fn ($label) => ['label' => $label], $columns), 'rows' => $rows]],
        ]];
    }
}

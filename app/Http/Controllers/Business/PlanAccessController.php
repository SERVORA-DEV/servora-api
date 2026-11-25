<?php

namespace App\Http\Controllers\Business;

use App\Exceptions\ActionRefused;
use App\Exceptions\NotFound;
use App\Exceptions\NotPermitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\ExportAuthorizeRequest;
use App\Http\Requests\Business\ExportRequest;
use App\Models\SpaBranch;
use App\Repository\SpaBusinessRepository;
use App\Service\Business\ReportExportService;
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
    public function export(ExportRequest $request, ReportExportService $reports)
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
        $accessible = $branches->orderBy('branch_name')->get();
        throw_if($accessible->isEmpty(), NotPermitted::class, 'No accessible branch selected.');
        $from = $data['date_from'];
        $to = $data['date_to'];
        $type = $data['type'];
        $table = $reports->table($accessible, $type, $from, $to, $filter);
        $rows = $table['rows'];

        // The search box: rows with any cell containing the words typed.
        if ($q = mb_strtolower(trim($data['search'] ?? ''))) {
            $rows = array_values(array_filter($rows, fn ($row) => collect($row)->contains(fn ($cell) => str_contains(mb_strtolower((string) $cell), $q))));
        }

        return ['data' => [
            'title' => ucfirst($type).' report', 'file' => "$type-$from-$to", 'period' => "$from to $to",
            'tables' => [['title' => $table['title'], 'unit' => $table['unit'], 'columns' => $table['columns'], 'rows' => array_values($rows)]],
        ]];
    }
}

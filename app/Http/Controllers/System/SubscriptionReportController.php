<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Report data only. No entitlement, checkout, renewal, or refund writes.
class SubscriptionReportController extends Controller
{
    public function __invoke(Request $request)
    {
        $input = $request->validate([
            'section' => 'required|in:subscriptions,payments',
            'per_page' => 'sometimes|integer|min:1|max:250', 'page' => 'sometimes|integer|min:1',
        ]);
        $iso = fn ($date) => $date ? Carbon::parse($date)->toIso8601String() : null;
        if ($input['section'] === 'subscriptions') {
            $page = DB::table('subscriptions as s')->join('spa_businesses as b', 'b.id', '=', 's.spa_business_id')
                ->leftJoin('subscription_plans as p', 'p.id', '=', 's.subscription_plan_id')
                ->whereIn('s.status', ['Active', 'Expired', 'Cancelled'])->whereNotNull('s.starts_at')
                ->select(['s.id', 's.uuid', 'b.uuid as business_uuid', 'b.business_name', 'p.category as plan', 'p.name as plan_name',
                    's.billing_cycle', 's.is_trial', 's.starts_at', 's.expires_at', 's.cancelled_at', 's.deleted_at', 'b.deleted_at as business_deleted_at'])
                ->orderBy('s.id')->paginate($input['per_page'] ?? 100);

            // Completed sessions preserve purchased plan versions across in-place upgrades.
            $changes = DB::table('checkout_sessions as c')->join('subscription_plans as p', 'p.id', '=', 'c.subscription_plan_id')
                ->whereIn('c.subscription_id', collect($page->items())->pluck('id'))
                ->where('c.status', 'completed')->whereIn('c.purpose', ['subscribe', 'renew', 'auto_renew', 'upgrade'])
                ->whereNotNull('c.completed_at')->orderBy('c.completed_at')->orderBy('c.id')
                ->get(['c.subscription_id', 'c.completed_at', 'c.billing_cycle', 'c.purpose', 'p.category as plan', 'p.name as plan_name'])
                ->groupBy('subscription_id');
            $page->through(function ($row) use ($iso, $changes) {
                $row->is_trial = (bool) $row->is_trial || $row->plan === 'Trial';
                foreach (['starts_at', 'expires_at', 'cancelled_at', 'deleted_at', 'business_deleted_at'] as $key) $row->$key = $iso($row->$key);
                $row->plan_changes = $changes->get($row->id, collect())->map(fn ($change) => [
                    'at' => $iso($change->completed_at), 'plan' => $change->plan, 'plan_name' => $change->plan_name,
                    'billing_cycle' => $change->billing_cycle, 'purpose' => $change->purpose,
                ])->values()->all();
                $row->sequence = (int) $row->id;
                unset($row->id);
                return $row;
            });
        } else {
            $page = DB::table('payments as p')->join('billings as i', 'i.id', '=', 'p.billing_id')
                ->leftJoin('subscriptions as s', 's.id', '=', 'i.subscription_id')
                ->leftJoin('spa_businesses as b', 'b.id', '=', DB::raw('COALESCE(p.spa_business_id, i.spa_business_id, s.spa_business_id)'))
                ->leftJoin('subscription_plans as sp', 'sp.id', '=', 's.subscription_plan_id')
                ->leftJoin('checkout_sessions as c', function ($join) {
                    $join->on('c.reference_id', '=', 'p.reference_number')->on('c.subscription_id', '=', 'i.subscription_id')
                        ->where('c.status', 'completed')->where('c.purpose', '!=', 'save_method');
                })
                ->leftJoin('subscription_plans as cp', 'cp.id', '=', 'c.subscription_plan_id')
                ->where('i.billing_type', 'Subscription')->whereIn('p.payment_status', ['Paid', 'Refunded'])
                ->whereNotNull('p.paid_at')->whereNull('p.deleted_at')->whereNull('i.deleted_at')
                ->where(fn ($q) => $q->where('s.is_trial', false)->orWhereNull('s.id'))
                ->where(fn ($q) => $q->whereRaw("COALESCE(cp.category, sp.category, '') != ?", ['Trial']))
                ->select(['p.uuid', 'b.uuid as business_uuid', 'b.business_name', 'p.amount', 'p.refunded_amount', 'p.paid_at'])
                ->selectRaw('COALESCE(p.spa_business_id, i.spa_business_id, s.spa_business_id) as business_id,
                    COALESCE(cp.category, sp.category) as plan, COALESCE(cp.name, sp.name) as plan_name,
                    COALESCE(c.billing_cycle, s.billing_cycle) as billing_cycle')
                ->orderBy('p.id')->paginate($input['per_page'] ?? 100)
                ->through(function ($row) use ($iso) {
                    $row->business_uuid ??= 'unavailable-'.($row->business_id ?? 'unknown');
                    $row->amount = (float) $row->amount;
                    $row->refunded_amount = (float) ($row->refunded_amount ?? 0);
                    $row->paid_at = $iso($row->paid_at);
                    unset($row->business_id);
                    return $row;
                });
        }

        return response()->json($page);
    }
}

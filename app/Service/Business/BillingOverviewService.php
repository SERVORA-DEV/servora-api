<?php

namespace App\Service\Business;

use App\Enums\BillingStatus;
use App\Enums\PaymentStatus;
use App\Models\Billing;
use App\Repository\BillingListQuery;
use Illuminate\Support\Facades\DB;

/**
 * The Billing & Payments overview card for a period, counted by the database: what was billed
 * against what came in and is still owed, how many bills are settled, unpaid, partly paid or
 * overdue, what was handed back, and how the money was paid (kept after refunds). Cancelled
 * bills are left out, as the card always has.
 */
class BillingOverviewService
{
    public function overview(array $branchIds, string $from, string $to): array
    {
        $bills = fn () => Billing::query()
            ->whereIn('billings.spa_branch_id', $branchIds)
            ->where('billings.billing_type', 'Appointment')
            ->where('billings.status', '!=', BillingStatus::Cancelled->value)
            ->whereDate('billings.issued_at', '>=', $from)
            ->whereDate('billings.issued_at', '<=', $to);

        // Per bill only the numbers: status, amount, kept, handed back.
        $rows = $bills()->toBase()
            ->select('billings.status', 'billings.amount')
            ->selectRaw(BillingListQuery::paidSql() . ' as kept')
            ->selectRaw(BillingListQuery::refundedSql() . ' as handed_back')
            ->get();

        $closed = [BillingStatus::Paid->value, BillingStatus::Refunded->value, BillingStatus::Cancelled->value];
        $out = ['bills' => 0, 'billed' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'settled' => 0, 'unpaid' => 0, 'partly' => 0, 'overdue' => 0, 'refunded' => 0.0, 'refundedBills' => 0];
        foreach ($rows as $r) {
            $amount = (float) $r->amount;
            $kept = (float) $r->kept;
            $balance = in_array($r->status, $closed, true) ? 0 : max(0, $amount - $kept);
            $out['bills']++;
            $out['billed'] += $amount;
            $out['collected'] += $kept;
            $out['outstanding'] += $balance;
            if ($balance <= 0) $out['settled']++;
            // As the bill shows itself (billingDisplayStatus on the web): owed with nothing paid is
            // Unpaid (or Overdue), owed with something paid is Partly paid.
            if (! in_array($r->status, $closed, true)) {
                if ($kept > 0) $out['partly']++;
                else $out['unpaid']++;
            }
            if ($r->status === BillingStatus::Overdue->value && $kept <= 0) $out['overdue']++;
            if ((float) $r->handed_back > 0) {
                $out['refunded'] += (float) $r->handed_back;
                $out['refundedBills']++;
            }
        }
        foreach (['billed', 'collected', 'outstanding', 'refunded'] as $k) $out[$k] = round($out[$k], 2);

        // How it was paid, kept after refunds; voided payments left out.
        $out['methods'] = DB::table('payments as p')
            ->joinSub($bills()->toBase()->select('billings.id'), 'b', 'b.id', '=', 'p.billing_id')
            ->whereNull('p.deleted_at')
            ->where('p.payment_status', '!=', PaymentStatus::Voided->value)
            ->groupBy('p.payment_method')
            ->selectRaw('p.payment_method as method, coalesce(sum(p.amount - coalesce(p.refunded_amount, 0)), 0) as amount, count(*) as count')
            ->get()
            ->map(fn ($m) => ['method' => $m->method, 'amount' => round((float) $m->amount, 2), 'count' => (int) $m->count])
            ->sortByDesc('amount')->values()->all();

        return $out;
    }
}

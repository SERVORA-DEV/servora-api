<?php

namespace App\Repository;

use App\Enums\BillingStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The filters the visit-bill lists share (Billing & Payments for the owner and the front desk),
 * in one place so the list, its status counts, its totals and the overview card count the same
 * bills.
 *
 * Filters, all optional:
 *   date_from, date_to   the day the bill was issued
 *   display_status       owed (anything still to pay: unpaid, partly paid, overdue), paid, or
 *                        refunded (refunded whole, or any money handed back on it)
 *   status               one stored status, and 'unpaid' / 'partially_paid' (older callers)
 *   payment_method       a payment taken by that method
 *   search               the bill or appointment number, or the client's name
 *
 * "Kept" (paidSql) is what the page has always called paid: Paid payments less what was handed
 * back; a voided payment never counts, nor a deleted one (payments are soft-deleted).
 */
class BillingListQuery
{
    public const DISPLAY_STATUSES = ['owed', 'paid', 'refunded'];

    /** SQL for a bill's kept payments (billings.* in scope). */
    public static function paidSql(): string
    {
        return '(select coalesce(sum(p.amount - coalesce(p.refunded_amount, 0)), 0) from payments p'
            . " where p.billing_id = billings.id and p.deleted_at is null and p.payment_status = '" . PaymentStatus::Paid->value . "')";
    }

    /** SQL for what was handed back on a bill (voided payments left out). */
    public static function refundedSql(): string
    {
        return '(select coalesce(sum(coalesce(p.refunded_amount, 0)), 0) from payments p'
            . " where p.billing_id = billings.id and p.deleted_at is null and p.payment_status != '" . PaymentStatus::Voided->value . "')";
    }

    public static function apply(Builder $query, array $filters, bool $withStatus = true): Builder
    {
        return $query
            ->when($withStatus && in_array($filters['display_status'] ?? null, self::DISPLAY_STATUSES, true),
                fn ($q) => self::byDisplayStatus($q, $filters['display_status']))
            ->when($withStatus && ($filters['status'] ?? null), fn ($q) => match ($filters['status']) {
                'unpaid' => $q->where('billings.status', BillingStatus::Pending->value)->whereDoesntHave('payments', fn ($p) => $p->where('payment_status', PaymentStatus::Paid->value)),
                'partially_paid' => $q->where('billings.status', BillingStatus::Pending->value)->whereHas('payments', fn ($p) => $p->where('payment_status', PaymentStatus::Paid->value)),
                default => $q->where('billings.status', $filters['status']),
            })
            ->when($filters['payment_method'] ?? null, fn ($q, $method) => $q->whereHas('payments', fn ($p) => $p->where('payment_method', $method)))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('billings.issued_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('billings.issued_at', '<=', $date))
            // whereLike: case-insensitive, ilike on PostgreSQL.
            ->when(is_string($filters['search'] ?? null) && trim($filters['search']) !== '', function ($q) use ($filters) {
                $search = trim($filters['search']);
                $q->where(fn ($qq) => $qq
                    ->whereLike('billing_number', "%{$search}%")
                    ->orWhereHas('appointment', fn ($a) => $a->whereLike('appointment_number', "%{$search}%"))
                    ->orWhereHas('appointment.client', fn ($c) => $c->whereLike('first_name', "%{$search}%")
                        ->orWhereLike('last_name', "%{$search}%")
                        ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", ['%' . mb_strtolower($search) . '%'])));
            });
    }

    private static function byDisplayStatus(Builder $q, string $status): Builder
    {
        return match ($status) {
            'owed' => $q->whereIn('billings.status', [BillingStatus::Pending->value, BillingStatus::Overdue->value]),
            'paid' => $q->where('billings.status', BillingStatus::Paid->value),
            'refunded' => $q->where(fn ($w) => $w->where('billings.status', BillingStatus::Refunded->value)
                ->orWhereRaw(self::refundedSql() . ' > 0')),
        };
    }

    /** How many bills each status choice holds, with every other filter applied. */
    public static function statusCounts(Builder $base): array
    {
        $row = (clone $base)->toBase()->selectRaw(
            'count(*) as all_bills,'
            . " sum(case when billings.status in ('" . BillingStatus::Pending->value . "', '" . BillingStatus::Overdue->value . "') then 1 else 0 end) as owed,"
            . " sum(case when billings.status = '" . BillingStatus::Paid->value . "' then 1 else 0 end) as paid,"
            . " sum(case when billings.status = '" . BillingStatus::Refunded->value . "' or " . self::refundedSql() . ' > 0 then 1 else 0 end) as refunded'
        )->first();

        return ['all' => (int) ($row->all_bills ?? 0), 'owed' => (int) ($row->owed ?? 0), 'paid' => (int) ($row->paid ?? 0), 'refunded' => (int) ($row->refunded ?? 0)];
    }

    /** What the bills add up to: owed, kept, and still to pay. */
    public static function totals(Builder $base): array
    {
        $inner = (clone $base)->toBase()->select('billings.amount', 'billings.status')->selectRaw(self::paidSql() . ' as kept');
        $closed = "'" . implode("', '", [BillingStatus::Paid->value, BillingStatus::Refunded->value, BillingStatus::Cancelled->value]) . "'";
        $row = DB::query()->fromSub($inner, 't')->selectRaw(
            'coalesce(sum(t.amount), 0) as amount, coalesce(sum(t.kept), 0) as paid,'
            . " coalesce(sum(case when t.status in ($closed) or t.amount - t.kept <= 0 then 0 else t.amount - t.kept end), 0) as balance"
        )->first();

        return ['amount' => round((float) $row->amount, 2), 'paid' => round((float) $row->paid, 2), 'balance' => round((float) $row->balance, 2)];
    }
}

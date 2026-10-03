<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\Billing;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\ClientPointEntry;
use App\Models\ClientVoucher;
use App\Models\CustomerProgram;
use App\Support\AppCache;
use App\Models\User;
use App\Repository\NotificationRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// What a client actually gets from a business's customer programs:
//
//   onBillPaid()      a paid appointment bill earns loyalty points (per ₱ paid
//                     — nothing is added per service or package) and any
//                     vouchers whose condition it meets (spend in one visit,
//                     every Nth visit, first visit)
//   onBillReopened()  a voided/refunded bill gives those back
//   redeemPoints()    points → a "Redeem loyalty points" voucher
//   issueMembershipVouchers()  the vouchers a membership bundles, per period
//   runDaily()        expiry (memberships, vouchers, points) + birthday vouchers
//
// Everything here runs inside the caller's transaction and is safe to repeat
// for the same bill: points are keyed to the bill, voucher counts are derived
// from what's already been issued.
class ProgramRewardService
{
    public function __construct(private NotificationRepository $notifications)
    {
    }

    // ── Module switch ─────────────────────────────────────────────────────

    // Programs run only while the owner's switch is on AND their plan
    // includes them. After a downgrade nothing is earned, redeemed or shown
    // to clients, but every balance is kept for when they upgrade again.
    // Both answers come from AppCache (no query once warm).
    public function enabledFor(int $businessId): bool
    {
        return $this->switchedOn($businessId) && $this->planAllows($businessId);
    }

    /** The owner's Customer Programs switch on its own. */
    public function switchedOn(int $businessId): bool
    {
        $settings = AppCache::businessSettings($businessId);

        return (bool) ($settings?->section('programs')['enabled'] ?? false);
    }

    public function planAllows(int $businessId): bool
    {
        return AppCache::planAllows($businessId, 'reward_access');
    }

    /** Active programs of a business offered at a branch. */
    public function offeredAt(int $businessId, int $branchId, ?string $type = null)
    {
        return CustomerProgram::where('spa_business_id', $businessId)
            ->where('is_active', true)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->whereHas('branches', fn ($q) => $q->where('spa_branches.id', $branchId))
            ->get();
    }

    // ── Earning ───────────────────────────────────────────────────────────

    public function onBillPaid(Billing $billing): void
    {
        if ($billing->billing_type !== 'Appointment' || ! $billing->appointment_id || ! $billing->spa_branch_id) {
            return;
        }
        $appointment = Appointment::find($billing->appointment_id);
        if (! $appointment?->client_id || ! $this->enabledFor($billing->spa_business_id)) {
            return;
        }

        $client = Client::whereKey($appointment->client_id)->lockForUpdate()->first();
        if (! $client) {
            return;
        }

        $programs = $this->offeredAt($billing->spa_business_id, $billing->spa_branch_id);
        $earned = [];

        $loyalty = $programs->firstWhere('type', 'loyalty');
        if ($loyalty && ($points = $this->creditPoints($client, $billing, $loyalty)) > 0) {
            $earned[] = number_format($points) . ' points';
        }

        $paidVisits = $this->paidVisits($client);
        foreach ($programs->where('type', 'voucher') as $voucher) {
            $due = match ($voucher->condition_kind) {
                'min_spend' => $voucher->condition_value && (float) $billing->amount + 0.004 >= $voucher->condition_value
                    && ! ClientVoucher::where('customer_program_id', $voucher->id)->where('source_billing_id', $billing->id)->where('status', '!=', 'cancelled')->exists() ? 1 : 0,
                'visits' => $voucher->condition_value
                    ? max(0, intdiv($paidVisits, $voucher->condition_value) - $this->issuedCount($client, $voucher, 'visits'))
                    : 0,
                'first_visit' => $paidVisits === 1 && $this->issuedCount($client, $voucher, 'first_visit') === 0 ? 1 : 0,
                default => 0,
            };
            for ($i = 0; $i < $due; $i++) {
                $this->issueVoucher($client, $voucher, $voucher->condition_kind === 'min_spend' ? 'spend' : $voucher->condition_kind, billing: $billing);
                $earned[] = $voucher->name;
            }
        }

        if ($earned) {
            $this->notifyClient($client, 'You earned rewards', 'From your visit to ' . ($appointment->branch?->branch_name ?? 'the spa') . ': ' . implode(', ', $earned) . '.', $appointment->uuid);
        }
    }

    // A paid bill reopened (payment voided) or refunded: the points it earned
    // come back off, and vouchers it earned that are still unused are
    // cancelled. On a refund, a voucher spent on the bill goes back to the
    // client's wallet. If the bill is paid again, onBillPaid earns afresh.
    public function onBillReopened(Billing $billing, bool $refunded = false): void
    {
        $appointment = $billing->appointment_id ? Appointment::find($billing->appointment_id) : null;
        $client = $appointment?->client_id ? Client::whereKey($appointment->client_id)->lockForUpdate()->first() : null;
        if (! $client) {
            return;
        }

        $earn = ClientPointEntry::where('billing_id', $billing->id)->where('type', 'earn')->first();
        if ($earn) {
            $take = min($earn->points, $client->current_points);
            $earn->update(['type' => 'void', 'remaining' => 0, 'note' => trim(($earn->note ?? '') . ' (bill reopened)')]);
            ClientPointEntry::create([
                'client_id' => $client->id,
                'billing_id' => $billing->id,
                'type' => 'reverse',
                'points' => -$take,
                'note' => "Bill {$billing->billing_number} " . ($refunded ? 'refunded' : 'reopened'),
            ]);
            $client->update([
                'current_points' => $client->current_points - $take,
                'lifetime_points' => max(0, $client->lifetime_points - $earn->points),
            ]);
        }

        ClientVoucher::where('source_billing_id', $billing->id)
            ->where('status', 'available')
            ->update(['status' => 'cancelled']);

        if ($refunded && $billing->client_voucher_id) {
            ClientVoucher::whereKey($billing->client_voucher_id)
                ->where('status', 'used')
                ->update(['status' => 'available', 'used_at' => null, 'used_billing_id' => null, 'used_by' => null]);
        }
    }

    // Points come from the amount paid only (points per ₱). Services and
    // packages used to carry their own "bonus points"; those are no longer
    // awarded — the old values are still in service_variants.loyalty_points /
    // packages.loyalty_points but nothing reads them.
    private function creditPoints(Client $client, Billing $billing, CustomerProgram $loyalty): int
    {
        if (ClientPointEntry::where('billing_id', $billing->id)->where('type', 'earn')->exists()) {
            return 0;
        }

        $rate = (float) $loyalty->value('pointsPerPeso', 0);
        $points = (int) floor((float) $billing->amount * $rate + 1e-9);
        if ($points <= 0) {
            return 0;
        }

        ClientPointEntry::create([
            'client_id' => $client->id,
            'billing_id' => $billing->id,
            'type' => 'earn',
            'points' => $points,
            'remaining' => $points,
            'expires_at' => $this->addPeriod(now(), $loyalty->value('expiryMonths', '12 months')),
            'note' => "Bill {$billing->billing_number}",
        ]);
        $client->update([
            'current_points' => $client->current_points + $points,
            'lifetime_points' => $client->lifetime_points + $points,
        ]);

        return $points;
    }

    // ── Points → voucher ──────────────────────────────────────────────────

    /** @return ClientVoucher|string the new voucher, or why it can't be redeemed */
    public function redeemPoints(Client $client, CustomerProgram $voucher, ?User $actor, ?int $branchId = null): ClientVoucher|string
    {
        if ($voucher->type !== 'voucher' || $voucher->condition_kind !== 'points' || ! $voucher->is_active) {
            return 'That voucher can\'t be bought with points.';
        }
        if ($voucher->spa_business_id !== $client->spa_business_id || ! $this->enabledFor($client->spa_business_id)) {
            return 'That voucher isn\'t offered by this spa.';
        }
        if ($branchId && ! $voucher->offeredAt($branchId)) {
            return 'That voucher isn\'t offered at this branch.';
        }

        $cost = (int) $voucher->condition_value;
        $client = Client::whereKey($client->id)->lockForUpdate()->first();
        $loyalty = CustomerProgram::where('spa_business_id', $client->spa_business_id)->where('type', 'loyalty')->where('is_active', true)->first();
        $minimum = (int) ($loyalty?->value('minRedeem', 0) ?? 0);

        if ($cost <= 0) {
            return 'This voucher has no points price set.';
        }
        if ($client->current_points < max($cost, $minimum)) {
            return $client->current_points < $cost
                ? 'Not enough points — ' . number_format($cost - $client->current_points) . ' more needed.'
                : 'Points can be redeemed from ' . number_format($minimum) . ' points.';
        }

        // Spend the points that expire first.
        $left = $cost;
        $entries = ClientPointEntry::where('client_id', $client->id)
            ->where('type', 'earn')->where('remaining', '>', 0)
            ->orderByRaw('expires_at IS NULL, expires_at')->orderBy('id')
            ->lockForUpdate()->get();
        foreach ($entries as $entry) {
            if ($left <= 0) {
                break;
            }
            $take = min($entry->remaining, $left);
            $entry->update(['remaining' => $entry->remaining - $take]);
            $left -= $take;
        }

        ClientPointEntry::create([
            'client_id' => $client->id,
            'type' => 'redeem',
            'points' => -$cost,
            'note' => $voucher->name,
            'created_by' => $actor?->id,
        ]);
        $client->update(['current_points' => $client->current_points - $cost]);

        return $this->issueVoucher($client, $voucher, 'points');
    }

    // ── Vouchers ──────────────────────────────────────────────────────────

    public function issueVoucher(
        Client $client,
        CustomerProgram $voucher,
        string $source,
        ?Billing $billing = null,
        ?ClientMembership $membership = null,
        ?CarbonInterface $expiresAt = null,
    ): ClientVoucher {
        return ClientVoucher::create([
            'client_id' => $client->id,
            'customer_program_id' => $voucher->id,
            'source' => $source,
            'source_billing_id' => $billing?->id,
            'client_membership_id' => $membership?->id,
            'issued_at' => now(),
            'expires_at' => $expiresAt ?? $this->addPeriod(now(), $voucher->value('validDays', '3 months')),
            'status' => 'available',
        ]);
    }

    public function issueMembershipVouchers(ClientMembership $membership): int
    {
        $membership->loadMissing('program.items', 'client');
        $count = 0;
        foreach ($membership->program->items->where('type', 'voucher')->where('is_active', true) as $voucher) {
            $this->issueVoucher($membership->client, $voucher, 'membership', membership: $membership, expiresAt: $membership->ends_at);
            $count++;
        }

        return $count;
    }

    // ── Progress (for the client app's "how to earn") ─────────────────────

    public function paidVisits(Client $client): int
    {
        return Billing::where('billing_type', 'Appointment')
            ->where('status', 'Paid')
            ->whereIn('appointment_id', Appointment::where('client_id', $client->id)->select('id'))
            ->count();
    }

    public function issuedCount(Client $client, CustomerProgram $voucher, string $source): int
    {
        return ClientVoucher::where('client_id', $client->id)
            ->where('customer_program_id', $voucher->id)
            ->where('source', $source)
            ->where('status', '!=', 'cancelled')
            ->count();
    }

    // ── Daily upkeep ──────────────────────────────────────────────────────

    public function runDaily(): array
    {
        $counts = ['memberships' => 0, 'vouchers' => 0, 'points' => 0, 'birthday' => 0];

        $counts['memberships'] = ClientMembership::where('status', 'active')->where('ends_at', '<=', now())->update(['status' => 'expired']);
        $counts['vouchers'] = ClientVoucher::where('status', 'available')->whereNotNull('expires_at')->where('expires_at', '<=', now())->update(['status' => 'expired']);

        ClientPointEntry::where('type', 'earn')->where('remaining', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->orderBy('id')->chunkById(200, function ($entries) use (&$counts) {
                foreach ($entries as $entry) {
                    DB::transaction(function () use ($entry, &$counts) {
                        $client = Client::whereKey($entry->client_id)->lockForUpdate()->first();
                        $entry = ClientPointEntry::whereKey($entry->id)->lockForUpdate()->first();
                        if (! $client || ! $entry || $entry->remaining <= 0) {
                            return;
                        }
                        $take = min($entry->remaining, $client->current_points);
                        ClientPointEntry::create(['client_id' => $client->id, 'type' => 'expire', 'points' => -$take, 'note' => 'Points expired']);
                        $entry->update(['remaining' => 0]);
                        $client->update(['current_points' => $client->current_points - $take]);
                        $counts['points'] += $take;
                    });
                }
            });

        $counts['birthday'] = $this->issueBirthdayVouchers();

        return $counts;
    }

    // "Given in birthday month": once per year, to clients whose linked
    // account has a birth date in the current month.
    private function issueBirthdayVouchers(): int
    {
        $issued = 0;
        $vouchers = CustomerProgram::where('type', 'voucher')->where('is_active', true)->where('condition_kind', 'birthday')->get();

        foreach ($vouchers as $voucher) {
            if (! $this->enabledFor($voucher->spa_business_id)) {
                continue;
            }
            $clients = Client::where('spa_business_id', $voucher->spa_business_id)
                ->where('is_active', true)
                ->whereHas('user', fn ($q) => $q->whereNotNull('birth_date')->whereMonth('birth_date', now()->month))
                ->whereDoesntHave('vouchers', fn ($q) => $q->where('customer_program_id', $voucher->id)->where('source', 'birthday')->whereYear('issued_at', now()->year))
                ->get();

            foreach ($clients as $client) {
                $this->issueVoucher($client, $voucher, 'birthday', expiresAt: now()->endOfMonth());
                $this->notifyClient($client, 'Happy birthday!', "{$voucher->name} is in your wallet this month.", null);
                $issued++;
            }
        }

        return $issued;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    // '3 months' / '12 months' / 'Weekly' / 'Monthly' / 'Quarterly' / 'Annually' / 'Never'.
    public function addPeriod(CarbonInterface $from, ?string $period): ?Carbon
    {
        $from = Carbon::instance($from);

        return match (true) {
            $period === null, $period === '', $period === 'Never' => null,
            $period === 'Weekly' => $from->copy()->addWeek(),
            $period === 'Monthly' => $from->copy()->addMonthNoOverflow(),
            $period === 'Quarterly' => $from->copy()->addMonthsNoOverflow(3),
            $period === 'Annually' => $from->copy()->addYearNoOverflow(),
            (bool) preg_match('/^(\d+)\s*month/i', $period, $m) => $from->copy()->addMonthsNoOverflow((int) $m[1]),
            (bool) preg_match('/^(\d+)\s*year/i', $period, $m) => $from->copy()->addYearsNoOverflow((int) $m[1]),
            (bool) preg_match('/^(\d+)\s*day/i', $period, $m) => $from->copy()->addDays((int) $m[1]),
            default => $from->copy()->addMonthsNoOverflow(3),
        };
    }

    public function notifyClient(Client $client, string $title, string $message, ?string $reference): void
    {
        if (! $client->user_id) {
            return;
        }
        $userId = $client->user_id;

        DB::afterCommit(function () use ($userId, $title, $message, $reference) {
            try {
                $this->notifications->create($userId, $title, $message, 'Reward', $reference);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}

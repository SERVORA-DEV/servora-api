<?php

namespace App\Service\Client;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\ClientPointEntry;
use App\Models\ClientVoucher;
use App\Models\CustomerProgram;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Service\Business\CustomerProgramService;
use App\Service\Business\ProgramRewardService;
use Illuminate\Support\Facades\DB;

// The client app's Rewards: what the signed-in client holds at each spa they
// visit (a clients row exists per business once they've booked), and one
// spa's rewards for its page. Same summary shape as the front desk's view —
// CustomerProgramService::summaryFor().
class ClientRewardsService
{
    public function __construct(
        private CustomerProgramService $programs,
        private ProgramRewardService $rewards,
    ) {
    }

    public function index(User $user)
    {
        $clients = Client::with('business')->where('user_id', $user->id)->where('is_active', true)->get();

        $rows = $clients
            ->filter(fn (Client $c) => $c->business && $this->rewards->enabledFor($c->spa_business_id))
            ->map(fn (Client $c) => $this->programs->summaryFor($c) + ['branch' => $this->lastBranch($c)])
            // A spa with no programs at all has nothing to show.
            ->filter(fn ($s) => $s['loyalty'] || count($s['vouchers']) || count($s['discounts']) || count($s['memberships']) || count($s['wallet']))
            ->values();

        return ['data' => $rows];
    }

    public function forBranch(User $user, string $branchUuid)
    {
        $branch = SpaBranch::with('business')->where('uuid', $branchUuid)->firstOrFail();
        $client = Client::where('spa_business_id', $branch->spa_business_id)->where('user_id', $user->id)->first()
            // Never booked here yet: show what's on offer, with nothing held.
            ?? (new Client(['spa_business_id' => $branch->spa_business_id, 'current_points' => 0, 'lifetime_points' => 0]))->setRelation('business', $branch->business);

        return ['data' => $this->programs->summaryFor($client, $branch->id) + [
            'branch' => ['uuid' => $branch->uuid, 'name' => $branch->branch_name],
        ]];
    }

    public function redeem(User $user, string $programUuid)
    {
        $program = CustomerProgram::where('uuid', $programUuid)->firstOrFail();
        $client = Client::where('spa_business_id', $program->spa_business_id)->where('user_id', $user->id)->first();
        if (! $client) {
            return response()->json(['message' => 'Book a visit at this spa first to start collecting points.'], 422);
        }

        return DB::transaction(function () use ($user, $client, $program) {
            $result = $this->rewards->redeemPoints($client, $program, $user, null, \App\Support\ProgramChannel::APP);
            if (is_string($result)) {
                return response()->json(['message' => $result], 422);
            }

            $client = $client->fresh('business');

            return ['data' => $this->programs->summaryFor($client) + ['branch' => $this->lastBranch($client)]];
        });
    }

    // Voucher `source` → how the client got it, in their words.
    private const VOUCHER_SOURCES = [
        'spend' => 'Earned by spending',
        'visits' => 'Earned for your visits',
        'points' => 'Claimed with points',
        'first_visit' => 'First-visit gift',
        'membership' => 'Included in your membership',
        'birthday' => 'Birthday gift',
    ];

    // What a client's points and rewards at one spa (business) have been,
    // newest first: points earned, spent, expired or taken back (the points
    // ledger), vouchers received — those claimed with points carry their
    // points price — and memberships bought, each with its date and amount.
    public function history(User $user, string $businessUuid)
    {
        $business = SpaBusiness::where('uuid', $businessUuid)->firstOrFail();
        $client = Client::where('spa_business_id', $business->id)->where('user_id', $user->id)->first();
        if (! $client) {
            return ['data' => ['business' => ['uuid' => $business->uuid, 'name' => $business->business_name], 'points' => 0, 'entries' => []]];
        }

        $points = ClientPointEntry::where('client_id', $client->id)->latest('id')->limit(300)->get();
        $vouchers = ClientVoucher::with('program')->where('client_id', $client->id)->latest('issued_at')->limit(300)->get();
        $memberships = ClientMembership::with('program')->where('client_id', $client->id)->latest('id')->limit(100)->get();

        // A voucher claimed with points was paid for by the 'redeem' entry made
        // with it (same name, same moment).
        $redeems = $points->where('type', 'redeem');
        $pointsPaid = function (ClientVoucher $v) use ($redeems): ?int {
            if ($v->source !== 'points' || ! $v->issued_at) {
                return null;
            }
            $entry = $redeems->first(fn (ClientPointEntry $e) => $e->note === ($v->program_name ?? $v->program?->name)
                && $e->created_at && abs($e->created_at->diffInSeconds($v->issued_at)) <= 60);

            return $entry ? abs($entry->points) : null;
        };

        $entries = collect()
            ->concat($points->map(fn (ClientPointEntry $e) => [
                'kind' => match ($e->type) {
                    'earn', 'void' => 'points_earned',
                    'redeem' => 'points_spent',
                    'expire' => 'points_expired',
                    'reverse' => 'points_reversed',
                    default => $e->points >= 0 ? 'points_earned' : 'points_spent',
                },
                'title' => match ($e->type) {
                    'earn', 'void' => 'Points earned',
                    'redeem' => 'Points spent',
                    'expire' => 'Points expired',
                    'reverse' => 'Points taken back',
                    default => 'Points adjusted',
                },
                'detail' => $e->type === 'redeem' && $e->note ? "On {$e->note}" : $e->note,
                'date' => $e->created_at?->toIso8601String(),
                'points' => in_array($e->type, ['earn', 'void'], true) ? abs($e->points) : $e->points,
                'amount' => null,
                'deal' => null,
                'status' => null,
            ]))
            ->concat($vouchers->map(fn (ClientVoucher $v) => [
                'kind' => 'voucher',
                'title' => $v->source === 'points' ? 'Voucher claimed with points' : 'Voucher received',
                'detail' => trim(($v->program_name ?? $v->program?->name ?? 'Voucher') . ' · ' . (self::VOUCHER_SOURCES[$v->source] ?? 'Voucher'), ' ·'),
                'date' => ($v->issued_at ?? $v->created_at)?->toIso8601String(),
                'points' => ($paid = $pointsPaid($v)) !== null ? -$paid : null,
                'amount' => null,
                'deal' => $v->dealProgram() ? $this->programs->dealText($v->dealProgram()) : null,
                'status' => $v->status,
            ]))
            ->concat($memberships->map(fn (ClientMembership $m) => [
                'kind' => 'membership',
                'title' => 'Membership purchased',
                'detail' => trim(($m->program_name ?? $m->program?->name ?? 'Membership')
                    . ($m->starts_at && $m->ends_at ? ' · ' . $m->starts_at->format('M j, Y') . ' – ' . $m->ends_at->format('M j, Y') : '')),
                'date' => ($m->created_at ?? $m->starts_at)?->toIso8601String(),
                'points' => null,
                'amount' => $m->price !== null ? (float) $m->price : null,
                'deal' => null,
                'status' => $m->status,
            ]))
            ->sortByDesc(fn ($row) => $row['date'] ?? '')
            ->values();

        return ['data' => [
            'business' => ['uuid' => $business->uuid, 'name' => $business->business_name],
            'points' => (int) $client->current_points,
            'entries' => $entries,
        ]];
    }

    // Where the client last visited this business — the spa page the app
    // opens from their Rewards card.
    private function lastBranch(Client $client): ?array
    {
        $branch = Appointment::with('branch')->where('client_id', $client->id)->latest('appointment_date')->first()?->branch
            ?? SpaBranch::where('spa_business_id', $client->spa_business_id)->orderBy('id')->first();

        return $branch ? ['uuid' => $branch->uuid, 'name' => $branch->branch_name] : null;
    }
}

<?php

namespace App\Service\Client;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\CustomerProgram;
use App\Models\SpaBranch;
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
            $result = $this->rewards->redeemPoints($client, $program, $user);
            if (is_string($result)) {
                return response()->json(['message' => $result], 422);
            }

            $client = $client->fresh('business');

            return ['data' => $this->programs->summaryFor($client) + ['branch' => $this->lastBranch($client)]];
        });
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

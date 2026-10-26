<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Service\Client\ClientRewardsService;
use Illuminate\Http\Request;

class ClientRewardsController extends Controller
{
    public function __construct(private ClientRewardsService $rewards)
    {
    }

    public function index(Request $request)
    {
        return $this->rewards->index($request->user());
    }

    public function forBranch(Request $request, string $branchUuid)
    {
        return $this->rewards->forBranch($request->user(), $branchUuid);
    }

    // Points earned and spent, vouchers and memberships at one spa (business).
    public function history(Request $request, string $businessUuid)
    {
        return $this->rewards->history($request->user(), $businessUuid);
    }

    public function redeem(Request $request)
    {
        $data = $request->validate(['program_uuid' => ['required', 'uuid']]);

        return $this->rewards->redeem($request->user(), $data['program_uuid']);
    }
}

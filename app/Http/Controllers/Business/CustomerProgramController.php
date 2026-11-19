<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\CustomerProgramRequest;
use App\Http\Requests\Business\MembershipPaymentRequest;
use App\Http\Requests\Business\ProgramBranchRequest;
use App\Http\Requests\Business\ProgramModuleRequest;
use App\Http\Requests\Business\SellMembershipRequest;
use App\Service\Business\CustomerProgramService;
use Illuminate\Http\Request;

class CustomerProgramController extends Controller
{
    public function __construct(private CustomerProgramService $programs)
    {
    }

    public function index(Request $request)
    {
        return $this->programs->index($request->user());
    }

    // The module switch (the owner's Programs page).
    public function module(ProgramModuleRequest $request)
    {
        $data = $request->validated();

        return $this->programs->setEnabled($request->user(), (bool) $data['enabled']);
    }

    public function store(CustomerProgramRequest $request)
    {
        return $this->programs->store($request->user(), $request->validated());
    }

    public function update(CustomerProgramRequest $request, string $uuid)
    {
        return $this->programs->update($request->user(), $uuid, $request->validated());
    }

    public function destroy(Request $request, string $uuid)
    {
        return $this->programs->destroy($request->user(), $uuid);
    }

    public function branch(ProgramBranchRequest $request, string $uuid, string $branchUuid)
    {
        $data = $request->validated();

        return $this->programs->setBranch($request->user(), $uuid, $branchUuid, (bool) $data['offered']);
    }

    public function memberships(Request $request)
    {
        return $this->programs->memberships($request->user(), $request->query('branch'));
    }

    public function sellMembership(SellMembershipRequest $request)
    {
        return $this->programs->sellMembership($request->user(), $request->validated());
    }

    public function renewMembership(MembershipPaymentRequest $request, string $uuid)
    {
        return $this->programs->renewMembership($request->user(), $uuid, $request->validated());
    }

    public function cancelMembership(Request $request, string $uuid)
    {
        return $this->programs->cancelMembership($request->user(), $uuid);
    }

    public function clientRewards(Request $request, string $uuid)
    {
        return $this->programs->clientRewards($request->user(), $uuid);
    }

    public function clientVouchers(Request $request, string $uuid)
    {
        return $this->programs->clientVouchers($request->user(), $uuid);
    }
}

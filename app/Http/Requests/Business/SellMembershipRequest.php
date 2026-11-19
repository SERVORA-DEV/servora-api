<?php

namespace App\Http\Requests\Business;

// POST api/business/programs/memberships (CustomerProgramController::sellMembership): a membership
// sold to a client at a branch, paid for as MembershipPaymentRequest says.
class SellMembershipRequest extends MembershipPaymentRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'client_uuid' => ['required', 'uuid'],
            'program_uuid' => ['required', 'uuid'],
            'branch_uuid' => ['required', 'uuid'],
        ];
    }
}

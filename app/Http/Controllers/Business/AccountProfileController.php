<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\AccountProfileRequest;

// The signed-in owner's, manager's or front officer's OWN details
// (Profile → Edit Profile). Only the caller's account: no target is accepted.
class AccountProfileController extends Controller
{
    public function update(AccountProfileRequest $request)
    {
        $user = $request->user();
        $user->forceFill(['phone_number' => $request->validated('phone_number')])->saveOrFail();

        return response()->json(['data' => ['phone_number' => $user->phone_number]]);
    }
}

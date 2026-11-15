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
        $fill = [];
        if ($request->exists('phone_number')) {
            $fill['phone_number'] = $request->validated('phone_number');
        }
        // An owner keeps their own gender and birth date; a staff login's
        // come from their employee record, so theirs are left alone.
        if ($user->role === 'business_owner') {
            foreach (['gender', 'birth_date'] as $key) {
                if ($request->exists($key)) {
                    $fill[$key] = $request->validated($key);
                }
            }
        }
        if ($fill) {
            $user->forceFill($fill)->saveOrFail();
        }

        return response()->json(['data' => [
            'phone_number' => $user->phone_number,
            'gender' => $user->gender,
            'birth_date' => $user->birth_date ? \Illuminate\Support\Carbon::parse($user->birth_date)->toDateString() : null,
        ]]);
    }
}

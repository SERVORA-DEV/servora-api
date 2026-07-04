<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, $id, $hash)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.'
            ], 404);
        }

        if (! hash_equals((string) $hash, sha1($user->email))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification link.'
            ], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'Email is already verified.'
            ]);
        }

        $user->markEmailAsVerified();

        $user->account_status = 'Active';
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully.'
        ]);
    }
}
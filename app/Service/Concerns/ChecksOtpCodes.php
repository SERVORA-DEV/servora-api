<?php

namespace App\Service\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The rules every one-time code (sign-up, password reset, the second factor by email) follows:
 * how long it lasts and how many wrong guesses it takes. One place, so the three flows can't drift.
 */
trait ChecksOtpCodes
{
    private const OTP_EXPIRY_MINUTES = 10;

    // Wrong guesses allowed per OTP / reset token before it is discarded.
    private const MAX_OTP_ATTEMPTS = 5;

    /**
     * Records a wrong OTP / reset-token guess against its row and, once the
     * cap is hit, deletes the row so the code can no longer be guessed;
     * the user has to request a fresh one. Shared by the password-reset and
     * registration OTP checks ($table is one of two fixed table names,
     * never user input).
     */
    private function rejectOtpGuess(string $table, User $user, int $attempts, string $message = 'Invalid code.')
    {
        $query = DB::table($table)->where('email', $user->email)->where('audience', $user->audience);

        if ($attempts + 1 >= self::MAX_OTP_ATTEMPTS) {
            $query->delete();

            return response()->json([
                'message' => 'Too many incorrect attempts. Please request a new code.'
            ], 429);
        }

        $query->increment('attempts');

        return response()->json(['message' => $message], 422);
    }
}

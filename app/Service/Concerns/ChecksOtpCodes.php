<?php

namespace App\Service\Concerns;

use App\Models\User;
use App\Support\Access\OneTimeCodes;

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
     * Records a wrong OTP / reset-token guess against its code and, once the
     * cap is hit, throws the code away so it can no longer be guessed;
     * the user has to request a fresh one. Shared by the password-reset and
     * registration OTP checks ($purpose is one of OneTimeCodes' purposes).
     */
    private function rejectOtpGuess(string $purpose, User $user, int $attempts, string $message = 'Invalid code.')
    {
        if ($attempts + 1 >= self::MAX_OTP_ATTEMPTS) {
            OneTimeCodes::forget($purpose, $user);

            return response()->json([
                'message' => 'Too many incorrect attempts. Please request a new code.'
            ], 429);
        }

        OneTimeCodes::countWrongGuess($purpose, $user);

        return response()->json(['message' => $message], 422);
    }
}

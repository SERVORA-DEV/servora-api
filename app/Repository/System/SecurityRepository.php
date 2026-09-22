<?php

namespace App\Repository\System;

use App\Models\User;

// Every method here is scoped through a given User instance (never a bare
// PersonalAccessToken::find() or User::find()) — these all act on "my own
// account", so an id/token belonging to someone else must never be
// reachable through this repository.
class SecurityRepository
{
    public function updatePassword(User $user, string $hashedPassword): void
    {
        // Already cast as 'hashed' on the model, so a plain string in also
        // works — passing the pre-hashed value here since callers already
        // went through Hash::make() once to compare against the old one.
        $user->forceFill(['password' => $hashedPassword])->save();
    }

    public function revokeOtherTokens(User $user, ?int $currentTokenId): void
    {
        $query = $user->tokens();

        if ($currentTokenId !== null) {
            $query->where('id', '!=', $currentTokenId);
        }

        $query->delete();
    }

    public function tokens(User $user)
    {
        return $user->tokens()->orderByDesc('last_used_at')->orderByDesc('created_at')->get();
    }

    public function revokeToken(User $user, int $tokenId): bool
    {
        return (bool) $user->tokens()->where('id', $tokenId)->delete();
    }

    public function setPendingTwoFactor(User $user, string $secret, array $hashedRecoveryCodes): void
    {
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => json_encode($hashedRecoveryCodes),
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function confirmTwoFactor(User $user): void
    {
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    }

    public function clearTwoFactor(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function setRecoveryCodes(User $user, array $hashedRecoveryCodes): void
    {
        $user->forceFill(['two_factor_recovery_codes' => json_encode($hashedRecoveryCodes)])->save();
    }

    public function setPendingPersonalEmail(User $user, string $email, string $otpHash): void
    {
        $user->forceFill([
            'personal_email' => $email,
            'personal_email_verified_at' => null,
            'personal_email_otp_hash' => $otpHash,
            'personal_email_otp_created_at' => now(),
        ])->save();
    }

    public function refreshPersonalEmailOtp(User $user, string $otpHash): void
    {
        $user->forceFill([
            'personal_email_otp_hash' => $otpHash,
            'personal_email_otp_created_at' => now(),
        ])->save();
    }

    public function verifyPersonalEmail(User $user): void
    {
        $user->forceFill([
            'personal_email_verified_at' => now(),
            'personal_email_otp_hash' => null,
            'personal_email_otp_created_at' => null,
        ])->save();
    }
}

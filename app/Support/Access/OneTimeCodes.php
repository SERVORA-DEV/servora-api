<?php

namespace App\Support\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;

// The emailed codes a person types in, one table for every kind (one_time_codes): the sign-up email
// check and the forgotten-password reset. One row per purpose, email and audience, so the two never
// overwrite each other. Only the hash is kept: the code itself goes out by email and nowhere else.
//
// (The second factor by email is not here: its code belongs to one sign-in attempt and is kept with
// that attempt — App\Service\Auth\SignInService.)
final class OneTimeCodes
{
    public const EMAIL_VERIFICATION = 'email_verification';

    public const PASSWORD_RESET = 'password_reset';

    private const TABLE = 'one_time_codes';

    /** A new code (or, for a reset, the reset token) for this account: replaces any earlier one, with no wrong guesses. */
    public static function put(string $purpose, User $user, string $hash): void
    {
        DB::table(self::TABLE)->updateOrInsert(
            self::key($purpose, $user),
            ['code_hash' => $hash, 'created_at' => now(), 'attempts' => 0],
        );
    }

    /** @return object{code_hash: string, attempts: int, created_at: ?string}|null */
    public static function find(string $purpose, User $user): ?object
    {
        return DB::table(self::TABLE)->where(self::key($purpose, $user))->first();
    }

    public static function forget(string $purpose, User $user): void
    {
        DB::table(self::TABLE)->where(self::key($purpose, $user))->delete();
    }

    public static function countWrongGuess(string $purpose, User $user): void
    {
        DB::table(self::TABLE)->where(self::key($purpose, $user))->increment('attempts');
    }

    /** @return array{purpose: string, email: string, audience: string} */
    private static function key(string $purpose, User $user): array
    {
        return ['purpose' => $purpose, 'email' => $user->email, 'audience' => $user->audience];
    }
}

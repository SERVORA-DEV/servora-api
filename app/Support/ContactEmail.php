<?php

namespace App\Support;

use App\Mail\ContactEmailVerificationMail;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

// The email an owner or manager puts in Settings for the business or a branch
// has to be confirmed: Servora emails it a button, and clicking the button
// confirms it. Nobody types a code.
//
// Not needed when it is the owner's own sign-up address (they already proved
// it by signing up). Phone numbers are not checked.
//
// The link is signed and carries a hash of the address, so it stops working if
// the email is changed again, or after two days; no token is stored.
class ContactEmail
{
    public const BUSINESS = 'business';
    public const BRANCH = 'branch';

    private const LINK_DAYS = 2;

    /** Whether the verified-at columns exist yet (the migration has been run). */
    public static function ready(): bool
    {
        $known = Cache::get('schema:contact_email');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('spa_businesses', 'business_email_verified_at') && Schema::hasColumn('spa_branches', 'email_verified_at');
        $ready ? Cache::forever('schema:contact_email', true) : Cache::put('schema:contact_email', false, 60);

        return $ready;
    }

    public static function normalize(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    /** Whether the address is the one the owner signed up with. */
    public static function isOwnersOwn(SpaBusiness $business, ?string $email): bool
    {
        $own = self::normalize($business->owner?->email);

        return $own !== '' && $own === self::normalize($email);
    }

    /** Whether the business's or branch's email is confirmed (true when there is none, or before the migration). */
    public static function isVerified(SpaBusiness|SpaBranch $subject): bool
    {
        if (! self::ready()) {
            return true;
        }

        return $subject instanceof SpaBusiness
            ? ($subject->business_email === null || $subject->business_email === '' || $subject->business_email_verified_at !== null)
            : ($subject->email === null || $subject->email === '' || $subject->email_verified_at !== null);
    }

    /**
     * An email was just saved on the business or a branch. Confirmed at once if
     * it is the owner's own sign-up address or is gone; otherwise it is marked
     * unconfirmed and the email with the button is sent.
     *
     * @return string 'none' | 'verified' | 'sent' | 'failed'
     */
    public static function afterSave(SpaBusiness|SpaBranch $subject, ?string $previous): string
    {
        if (! self::ready()) {
            return 'verified';
        }

        $business = $subject instanceof SpaBusiness ? $subject : $subject->business;
        $column = $subject instanceof SpaBusiness ? 'business_email_verified_at' : 'email_verified_at';
        $email = $subject instanceof SpaBusiness ? $subject->business_email : $subject->email;

        if ($email === null || trim($email) === '') {
            $subject->forceFill([$column => null])->save();

            return 'none';
        }

        // The same address again: nothing changes.
        if (self::normalize($email) === self::normalize($previous) && $subject->{$column} !== null) {
            return 'verified';
        }

        if ($business && self::isOwnersOwn($business, $email)) {
            $subject->forceFill([$column => now()])->save();

            return 'verified';
        }

        $subject->forceFill([$column => null])->save();

        return self::send($subject) ? 'sent' : 'failed';
    }

    /** Sends the email with the confirmation button to the address on file. */
    public static function send(SpaBusiness|SpaBranch $subject): bool
    {
        $isBusiness = $subject instanceof SpaBusiness;
        $email = $isBusiness ? $subject->business_email : $subject->email;
        if (! $email) {
            return false;
        }

        $url = URL::temporarySignedRoute('contact-email.verify', now()->addDays(self::LINK_DAYS), [
            'kind' => $isBusiness ? self::BUSINESS : self::BRANCH,
            'uuid' => $subject->uuid,
            'hash' => sha1(self::normalize($email)),
        ]);

        try {
            \Illuminate\Support\Facades\Mail::to($email)->send(new ContactEmailVerificationMail(
                $isBusiness ? $subject->business_name : "{$subject->branch_name}",
                $isBusiness ? 'your business' : 'this branch',
                $email,
                $url,
            ));

            return true;
        } catch (\Throwable $e) {
            Log::error('Could not send the contact email verification', ['email' => $email, 'exception' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The button was clicked.
     *
     * @return string 'success' | 'already' | 'invalid'
     */
    public static function confirm(string $kind, string $uuid, string $hash): string
    {
        if (! self::ready()) {
            return 'invalid';
        }

        $subject = $kind === self::BUSINESS
            ? SpaBusiness::where('uuid', $uuid)->first()
            : ($kind === self::BRANCH ? SpaBranch::where('uuid', $uuid)->first() : null);
        if (! $subject) {
            return 'invalid';
        }

        $isBusiness = $subject instanceof SpaBusiness;
        $email = $isBusiness ? $subject->business_email : $subject->email;
        // Changed since the email was sent: that link is for the old address.
        if (! $email || ! hash_equals($hash, sha1(self::normalize($email)))) {
            return 'invalid';
        }

        $column = $isBusiness ? 'business_email_verified_at' : 'email_verified_at';
        if ($subject->{$column} !== null) {
            return 'already';
        }

        $subject->forceFill([$column => now()])->save();

        return 'success';
    }
}

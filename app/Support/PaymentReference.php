<?php

namespace App\Support;

// What a payment's reference number may look like, by method. Mirrors the
// web's utils/paymentReference.ts so the desk is told before it saves.
//
//   GCash          the Ref No. on the GCash receipt: digits only, 10–13 of
//                  them (spaces as printed are fine, they are ignored).
//   Maya           the Reference ID: letters and digits (e.g. MRCJ20240714123).
//   Credit/Debit   the approval (auth) code on the card slip: 4–12 letters
//                  and digits, usually six.
//   Bank Transfer, Online Banking, QR Code, Other
//                  the bank's or app's reference: letters, digits and hyphens.
//
// Every reference other than GCash may hold letters, but it has to hold at
// least one digit: a word typed in by mistake is refused.
final class PaymentReference
{
    /** The reference with spaces taken out and letters upper-cased. */
    public static function normalize(?string $reference): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $reference));
    }

    /** Why this reference can't be right for the method; null when it can. */
    public static function problem(string $method, ?string $reference): ?string
    {
        $ref = self::normalize($reference);
        if ($ref === '') {
            return null;
        }

        if ($method === 'GCash') {
            return preg_match('/^\d{10,13}$/', $ref) ? null : 'A GCash reference number is 10 to 13 digits, numbers only (the Ref No. on the GCash receipt).';
        }

        if (in_array($method, ['Credit Card', 'Debit Card'], true)) {
            return preg_match('/^[A-Z0-9]{4,12}$/', $ref) && preg_match('/\d/', $ref)
                ? null : 'Enter the approval code from the card slip: 4 to 12 letters and numbers, usually six.';
        }

        if ($method === 'Maya') {
            return preg_match('/^[A-Z0-9]{6,30}$/', $ref) && preg_match('/\d/', $ref)
                ? null : 'A Maya reference is 6 to 30 letters and numbers (the Reference ID on the Maya receipt).';
        }

        return preg_match('/^[A-Z0-9][A-Z0-9-]{3,39}$/', $ref) && preg_match('/\d/', $ref)
            ? null : 'Enter the reference from the receipt: 4 to 40 letters, numbers or hyphens, with at least one number.';
    }
}

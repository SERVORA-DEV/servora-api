<?php

namespace App\Support;

// May a service that has already started or finished be taken off a visit?
// (One that hasn't started is simply removed — that needs no rule.)
//
// Voiding takes it off what the client is charged and off the therapist's
// commission, so it is only allowed while nothing has been settled against
// it: the visit is still open, no payment has been taken, and the bill (if
// there is one) carries no discount worked out from the old total. A finished,
// paid visit is locked altogether (FinishedVisit).
//
// Pure: plain values in, a reason (or nothing) out — tested in
// tests/Unit/ServiceVoidRulesTest. AppointmentService::voidService applies it.
class ServiceVoidRules
{
    /**
     * @param  string  $serviceStatus  'Pending' | 'In Progress' | 'Completed' | 'Cancelled'
     * @param  string  $appointmentStatus  the visit's status
     * @param  ?array{status: string, kept: float, discount: float}  $bill  null when the visit has no bill yet;
     *         kept = money paid and not refunded or voided
     * @param  bool  $leavesNothing  voiding it would leave the visit with nothing to charge
     */
    public static function problem(string $serviceStatus, string $appointmentStatus, ?array $bill, bool $leavesNothing): ?string
    {
        if ($serviceStatus === 'Cancelled') {
            return 'This service has already been removed.';
        }
        if (in_array($appointmentStatus, ['cancelled', 'no_show'], true)) {
            return 'This appointment was cancelled — there is nothing to void.';
        }
        if ($locked = FinishedVisit::problem($appointmentStatus)) {
            return $locked;
        }

        if ($bill === null) {
            return null;
        }

        if (in_array($bill['status'], ['Paid', 'Refunded', 'Cancelled'], true)) {
            return 'The bill for this visit is closed.';
        }
        if ($bill['kept'] > 0.004) {
            return 'A payment has already been taken on this bill. Void that payment first, or use a refund instead.';
        }
        if ($bill['discount'] > 0.004) {
            return 'Remove the discount on the bill first — it was worked out from the current total.';
        }
        if ($leavesNothing) {
            return 'This is the only charge on the bill, so nothing would be left to bill.';
        }

        return null;
    }

    /** What is kept on the service line, so the reason travels with the record. */
    public static function note(string $reason, string $by, ?string $existing = null): string
    {
        $note = "Voided by {$by}: " . trim($reason);
        $existing = trim((string) $existing);

        return $existing !== '' ? "{$note} · Note: {$existing}" : $note;
    }
}

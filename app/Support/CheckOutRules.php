<?php

namespace App\Support;

// A therapist who is performing a service right now stays checked in: they
// can't be checked out, sent home early, or have today's attendance cleared
// until that service is completed (or cancelled). Otherwise the floor would
// show someone as gone who is in a room with a client, and their hours would
// stop before the work did.
//
// Pure — tested in tests/Unit/CheckOutRulesTest.
// App\Service\Business\ServingNow finds what they are doing; the attendance
// services (front desk and owner/manager) apply this.
class CheckOutRules
{
    /**
     * @param  string  $name  the staff member
     * @param  ?array{service: ?string, client: ?string}  $serving  what they are performing right now, null when nothing
     */
    public static function problem(string $name, ?array $serving): ?string
    {
        if ($serving === null) {
            return null;
        }

        $what = trim((string) ($serving['service'] ?? '')) ?: 'a service';
        $client = trim((string) ($serving['client'] ?? ''));

        return "{$name} is performing {$what}" . ($client !== '' ? " for {$client}" : '') . ' right now. Complete that service first.';
    }
}

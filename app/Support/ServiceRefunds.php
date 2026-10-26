<?php

namespace App\Support;

// What of a bill's money belongs to each service, so the checkout can show — and
// the desk can hand back — the part of the payment a single service accounts
// for ("this massage: ₱500 refundable").
//
// A service's share of the bill is what it is charged (after its own discount),
// scaled by whatever bill-level discount was taken off the whole. How much of
// that has actually been paid follows how much of the bill has been paid:
// a bill paid half is paid half on every service. Money already handed back
// for a service comes off it, and the services together never show more than
// the bill still keeps.
//
// Pure: plain values in, plain values out — tested in
// tests/Unit/ServiceRefundsTest. BillingService builds the lines from a bill
// and applies a refund to one of them.
class ServiceRefunds
{
    /**
     * @param  list<array{id: int|string, charge: float, refunded: float}>  $lines  what each service is charged, and what was already given back for it
     * @param  float  $homeFee  the home-service fee on the bill (it has no service line)
     * @param  float  $billDiscount  the discount taken off the whole bill
     * @param  float  $received  all the money taken on the bill, before any refund
     * @param  float  $kept  what the bill still keeps: received, less refunds
     * @return array<int|string, array{charge: float, net: float, paid: float, refunded: float, refundable: float}>
     */
    public static function plan(array $lines, float $homeFee, float $billDiscount, float $received, float $kept): array
    {
        $charged = array_sum(array_map(fn ($l) => max(0.0, (float) $l['charge']), $lines)) + max(0.0, $homeFee);
        $owed = max(0.0, $charged - max(0.0, $billDiscount));
        $scale = $charged > 0 ? $owed / $charged : 0.0;
        $covered = $owed > 0 ? min(1.0, max(0.0, $received) / $owed) : 0.0;

        $out = [];
        foreach ($lines as $line) {
            $net = max(0.0, (float) $line['charge']) * $scale;
            $paid = $net * $covered;
            $refunded = max(0.0, (float) $line['refunded']);
            $out[$line['id']] = [
                'charge' => round((float) $line['charge'], 2),
                'net' => round($net, 2),
                'paid' => round($paid, 2),
                'refunded' => round($refunded, 2),
                'refundable' => max(0.0, round($paid - $refunded, 2)),
            ];
        }

        // Never more than the bill still holds, whatever the rounding.
        $sum = array_sum(array_column($out, 'refundable'));
        if ($sum > $kept + 0.004) {
            $factor = $sum > 0 ? max(0.0, $kept) / $sum : 0.0;
            foreach ($out as &$row) {
                $row['refundable'] = round($row['refundable'] * $factor, 2);
            }
            unset($row);
        }

        return $out;
    }
}

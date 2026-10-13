<?php

namespace App\Support;

// "Refused, with a recorded override."
//
// When the front desk does something a booking rule doesn't allow
// (App\Support\FrontDeskPolicy), it is refused — unless the person is allowed
// to override booking rules AND gives a reason. The reason is kept on the
// booking with the rule it waived, who did it and when, and the manager is
// told (App\Service\Business\PolicyGate does the keeping and the telling).
//
// A rule that is a switch in Settings is never overridable from here.
//
// Pure — tested in tests/Unit/PolicyOverrideTest.
class PolicyOverride
{
    public const MIN_REASON = 3;

    /**
     * @param  array<int, array{rule: string, message: string, overridable: bool}>  $problems
     * @param  ?string  $reason  what the person typed, if anything
     * @param  bool  $mayOverride  the person has the "Override booking rules" permission
     * @return array{ok: bool, message: ?string, rules: array, can_override: bool, kept: array}
     *         ok: go ahead (kept = what to record, empty when no rule was broken);
     *         otherwise the refusal to show — can_override says whether a reason box should be offered.
     */
    public static function decide(array $problems, ?string $reason, bool $mayOverride): array
    {
        $problems = array_values($problems);
        if (! $problems) {
            return ['ok' => true, 'message' => null, 'rules' => [], 'can_override' => false, 'kept' => []];
        }

        $rules = array_map(fn (array $p) => ['rule' => $p['rule'], 'message' => $p['message']], $problems);
        $message = implode(' ', array_column($problems, 'message'));
        $fixed = array_filter($problems, fn (array $p) => ! $p['overridable']);

        if ($fixed) {
            return ['ok' => false, 'message' => implode(' ', array_column($fixed, 'message')), 'rules' => $rules, 'can_override' => false, 'kept' => []];
        }
        if (! $mayOverride) {
            return ['ok' => false, 'message' => $message . ' It needs someone allowed to override booking rules.', 'rules' => $rules, 'can_override' => false, 'kept' => []];
        }

        $reason = trim((string) $reason);
        if (mb_strlen($reason) < self::MIN_REASON) {
            return ['ok' => false, 'message' => $message . ' Give a reason to go ahead anyway.', 'rules' => $rules, 'can_override' => true, 'kept' => []];
        }

        return [
            'ok' => true, 'message' => null, 'rules' => $rules, 'can_override' => true,
            'kept' => array_map(fn (array $p) => ['rule' => $p['rule'], 'message' => $p['message'], 'reason' => $reason], $problems),
        ];
    }
}

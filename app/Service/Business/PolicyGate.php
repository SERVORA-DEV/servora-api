<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Support\AppCache;
use App\Support\PolicyOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// Where the front desk meets the owner's and manager's booking rules.
//
// Every front desk action that a rule can stand in the way of passes what it
// found (App\Support\FrontDeskPolicy) through check():
//   - nothing broken              → carry on;
//   - a rule broken               → 422 with the rule(s) and whether this
//                                   person may override:
//                                   { message, policy: { rules, can_override } }
//   - broken, but overridden      → carry on, and record() keeps the override
//                                   on the booking, in the audit log, and
//                                   tells the manager.
//
// The reason comes with the request as `override_reason` (read here, so every
// endpoint takes it without each having to pass it along). Who may override:
// the owner; a manager; a front desk account the owner ticked "Override
// booking rules" for (permission policy_override, off by default).
class PolicyGate
{
    public function __construct(
        private AuditLogRepository $auditLog,
        private StaffNotifier $staffNotifier,
    ) {
    }

    /** Whether the columns this keeps things in exist yet (the migration has been run). */
    public static function ready(): bool
    {
        $known = Cache::get('schema:front_desk_policy');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('appointments', 'policy_overrides') && Schema::hasColumn('user_permissions', 'policy_override');
        $ready ? Cache::forever('schema:front_desk_policy', true) : Cache::put('schema:front_desk_policy', false, 60);

        return $ready;
    }

    public function mayOverride(User $user): bool
    {
        if ($user->role === 'business_owner') {
            return true;
        }
        if (! in_array('policy_override', config("permission.{$user->role}", []), true)) {
            return false;
        }
        // Before the permission has a column to live in, it is the manager's.
        $byDefault = $user->role === 'manager';
        if (! self::ready()) {
            return $byDefault;
        }
        $row = $user->relationLoaded('permission') ? $user->permission : AppCache::permission($user->id);

        return $row ? $row->getAttribute('policy_override') === true : $byDefault;
    }

    /**
     * @param  array<int, array{rule: string, message: string, overridable: bool}>  $problems
     * @return JsonResponse|array  the refusal to return as it is, or what to keep (empty when nothing was broken)
     */
    public function check(User $user, array $problems): JsonResponse|array
    {
        $decision = PolicyOverride::decide($problems, request()->input('override_reason'), $this->mayOverride($user));
        if (! $decision['ok']) {
            return response()->json([
                'message' => $decision['message'],
                'policy' => ['rules' => $decision['rules'], 'can_override' => $decision['can_override']],
            ], 422);
        }

        return $decision['kept'];
    }

    /** Keeps what was overridden on the booking, in the audit log, and tells the manager. */
    public function record(Appointment $appointment, array $kept, User $user): void
    {
        if (! $kept) {
            return;
        }

        $staff = $user->staff;
        $by = trim(($staff?->first_name ?? $user->first_name ?? '') . ' ' . ($staff?->last_name ?? $user->last_name ?? '')) ?: 'Someone';
        $entries = array_map(fn (array $k) => $k + ['by' => $by, 'at' => now()->toIso8601String()], $kept);

        if (self::ready()) {
            $appointment->update(['policy_overrides' => array_merge($appointment->policy_overrides ?? [], $entries)]);
        }

        $this->auditLog->record($user->id, 'appointments', $appointment->id, 'Update', null, ['policy_overrides' => $entries], request());

        $rules = implode(' ', array_column($kept, 'message'));
        $this->staffNotifier->appointment(
            $appointment,
            'Booking rule overridden',
            "{$by} went ahead on booking {$appointment->appointment_number} despite a rule — {$rules} Reason: {$kept[0]['reason']}.",
            exceptUserId: $user->id,
        );
    }
}

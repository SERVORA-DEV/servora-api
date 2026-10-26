<?php

namespace App\Repository;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\ActivityRecorder;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuditLogRepository
{
    public function record(
        ?int $userId,
        string $tableName,
        ?int $recordId,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null
    ): AuditLog {
        $row = AuditLog::create([
            'user_id' => $userId,
            'table_name' => $tableName,
            'record_id' => $recordId,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);

        // Written during a business request: RecordBusinessActivity gives it
        // the business and branch, and tells its details in the Audit Log's
        // row for the action.
        app(ActivityRecorder::class)->noteExplicit($row);

        return $row;
    }

    // Login / Logout / Login Failed rows for Settings → Login History. Takes
    // the ip/user-agent explicitly rather than a Request: when a session is
    // revoked from ANOTHER device, the row must describe the revoked
    // session's device (stored on its token), not the requester's.
    public function recordAuthEvent(
        int $userId,
        string $action,
        array $details,
        ?string $ipAddress,
        ?string $userAgent
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $userId,
            'table_name' => 'users',
            'record_id' => $userId,
            'action' => $action,
            'new_values' => $details,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ] + $this->signInScope($userId, $action, $details));
    }

    // The owner's and their logins' sign-ins also show in the business's
    // Audit Log, under Sign-ins, with their own copy of who it was.
    private function signInScope(int $userId, string $action, array $details): array
    {
        try {
            if (! AuditLog::businessReady()) {
                return [];
            }
            $user = User::find($userId);
            if (! $user || ! in_array($user->role, ['business_owner', 'manager', 'front_officer'], true)) {
                return [];
            }
            $business = app(SpaBusinessRepository::class)->findForUser($user);
            if (! $business) {
                return [];
            }
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $summary = match ($action) {
            'Login' => 'Signed in',
            'Logout' => match ($details['reason'] ?? null) {
                'revoked' => 'Was signed out from another device',
                'password_changed' => 'Was signed out: password changed',
                'password_reset' => 'Was signed out: password reset',
                default => 'Signed out',
            },
            'Login Failed' => ($details['reason'] ?? null) === 'invalid_two_factor_code'
                ? 'Failed to sign in: wrong two-factor code'
                : 'Failed to sign in: wrong password',
            default => $action,
        };

        return [
            'spa_business_id' => $business->id,
            'event' => 'sign_in.' . str_replace(' ', '_', strtolower($action)),
            'area' => 'sign_in',
            'summary' => $summary,
            'actor_name' => mb_substr($user->displayName(), 0, 160),
            'actor_role' => $user->role,
            // A login's highest level anywhere (a sign-in is at no one branch).
            'actor_level' => $user->role === 'business_owner'
                ? 'owner'
                : (\App\Models\AccountBranch::runsBranch(\App\Support\AccountScope::levels($user)) ? 'management' : 'operation'),
        ];
    }

    // A session (Sanctum token) ending for a reason other than its own
    // device signing out — revoked from another device, password changed,
    // password reset. Uses the device details stored on the token itself.
    public function recordSessionEnded(int $userId, PersonalAccessToken $token, string $reason): AuditLog
    {
        return $this->recordAuthEvent(
            $userId,
            'Logout',
            ['reason' => $reason, 'token_id' => $token->id],
            $token->ip_address,
            $token->user_agent ?? $token->name,
        );
    }

    // An administrator action in a service that doesn't receive the actor or
    // request (e.g. ServiceTemplateService::updateTemplate($uuid, $payload))
    // — only ever called from system_administrator routes, so the signed-in
    // user IS the acting admin.
    public function recordAdminAction(
        string $tableName,
        ?int $recordId,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null
    ): AuditLog {
        return $this->record(auth()->id(), $tableName, $recordId, $action, $oldValues, $newValues, request());
    }

    /**
     * Only the keys whose value actually changed, as [old, new] — for an
     * Update row's Changes section. Timestamps are noise; a password is
     * never written to the log, just flagged as changed.
     *
     * @return array{0: ?array, 1: ?array}
     */
    public function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            if (in_array($key, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            $previous = $before[$key] ?? null;

            if ($key === 'password') {
                if ($value !== null && $value !== '') {
                    $old[$key] = '••••••';
                    $new[$key] = '(changed)';
                }
                continue;
            }

            if (is_scalar($value) || $value === null) {
                // Loose on purpose: '499.00' vs 499 or 1 vs true is not a change.
                if ((string) $this->normalize($previous) !== (string) $this->normalize($value)) {
                    $old[$key] = $previous;
                    $new[$key] = $value;
                }
            }
        }

        return [$old ?: null, $new ?: null];
    }

    private function normalize($value)
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_numeric($value) ? (string) (float) $value : $value;
    }

    // Settings > Audit Logs (and the dashboard card below) show system
    // administrators' activity only — their actions on the platform and the
    // sign-in/security events on their own accounts. Owner/staff/client rows
    // stay in the table (attendance history, each user's own Login History)
    // but aren't part of the admin audit trail.
    public function paginate(int $perPage = 15)
    {
        return $this->administratorLogs()->latest()->paginate($perPage);
    }

    // Top-N for the system dashboard's "Audit Logs" card — a plain
    // limit()->get() rather than paginate() since no page count is needed.
    public function recent(int $limit = 8)
    {
        return $this->administratorLogs()->latest()->limit($limit)->get();
    }

    private function administratorLogs()
    {
        return AuditLog::with('user')
            ->whereHas('user', fn ($query) => $query->where('role', 'system_administrator'));
    }

    // A user's own login/logout history (Settings > Security > Login
    // History) — filtered by action rather than table_name+record_id like
    // forRecord(), since that would also surface unrelated admin edits made
    // to this user's row (e.g. another admin changing their permissions).
    public function loginHistoryForUser(int $userId, int $limit = 50)
    {
        return AuditLog::where('user_id', $userId)
            ->whereIn('action', ['Login', 'Logout', 'Login Failed'])
            ->latest()
            ->limit($limit)
            ->get();
    }

    // Full change history for one record (e.g. an attendance row's "History"
    // section) — newest first, same limit-without-pagination shape as recent().
    public function forRecord(string $tableName, int $recordId, int $limit = 20)
    {
        return AuditLog::with('user.staff')
            ->where('table_name', $tableName)
            ->where('record_id', $recordId)
            ->latest()
            ->limit($limit)
            ->get();
    }
}

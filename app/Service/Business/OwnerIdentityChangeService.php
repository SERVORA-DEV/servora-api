<?php

namespace App\Service\Business;

use App\Enums\ChangeRequestStatus;
use App\Enums\VerificationStatus;
use App\Exceptions\ActionRefused;
use App\Exceptions\NotSetUp;
use App\Mail\IdentityChangeEmailOtpMail;
use App\Models\OwnerIdentityChange;
use App\Models\OwnerIdentityVerification;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\System\AdminUsersRepository;
use App\Service\Concerns\SendsOtpMail;
use App\Service\Uploads\DocumentUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

// Changing the name or email of a verified owner account. The owner's name
// came from their verified ID, and the email is how they sign in, so neither
// is edited in place: the owner sends the new details, proves a new email
// with a code, takes a new ID photo and face scan, and a system administrator
// checks them, as at the first verification. Until approved, the account
// keeps its name and email. Nothing is deleted: a replaced ID stays on file
// with the request it came with.
class OwnerIdentityChangeService
{
    use SendsOtpMail;

    private const CODE_MINUTES = 10;
    private const RESEND_SECONDS = 60;

    public function __construct(
        private DocumentUploadService $documents,
        private AuditLogRepository $auditLogs,
        private NotificationRepository $notifications,
        private AdminUsersRepository $admins,
    ) {}

    // ── Owner ──────────────────────────────────────────────────────────

    public function show(User $owner)
    {
        $identity = OwnerIdentityVerification::where('user_id', $owner->id)->first();
        $open = $this->ready() ? $this->openFor($owner) : null;
        $last = $this->ready() && ! $open
            ? OwnerIdentityChange::where('user_id', $owner->id)->whereIn('status', [ChangeRequestStatus::Approved->value, ChangeRequestStatus::Rejected->value])->latest('reviewed_at')->first()
            : null;

        return response()->json(['data' => [
            // The ID on the account now, shown on the profile.
            'current' => [
                'id_type' => $identity?->id_type,
                'status' => $identity?->status ?? VerificationStatus::Unregistered->value,
                'verified_at' => $identity?->verified_at?->toIso8601String(),
            ],
            'request' => $open ? $this->present($open) : null,
            'last' => $last ? $this->present($last) : null,
            'available' => $this->ready(),
        ]]);
    }

    public function saveDetails(User $owner, array $fields)
    {
        $this->guard($owner);

        $email = mb_strtolower(trim($fields['email']));
        $same = trim($fields['first_name']) === (string) $owner->first_name
            && trim((string) ($fields['middle_name'] ?? '')) === (string) ($owner->middle_name ?? '')
            && trim($fields['last_name']) === (string) $owner->last_name
            && trim((string) ($fields['suffix'] ?? '')) === (string) ($owner->suffix ?? '')
            && $email === mb_strtolower((string) $owner->email);
        if ($same) {
            return response()->json(['message' => 'Nothing changed. Change your name or email first.'], 422);
        }
        if ($email !== mb_strtolower((string) $owner->email)
            && User::query()->whereRaw('LOWER(email) = ?', [$email])->where('id', '!=', $owner->id)->exists()) {
            return response()->json(['message' => 'That email is already used by another SERVORA account.', 'errors' => ['email' => ['That email is already used by another SERVORA account.']]], 422);
        }

        $change = $this->openFor($owner);
        if ($change && $change->status !== ChangeRequestStatus::Draft->value) {
            return response()->json(['message' => 'Your request is with the SERVORA team. Cancel it first to change it.'], 422);
        }

        $emailBefore = $change?->email;
        $values = [
            'first_name' => trim($fields['first_name']),
            'middle_name' => trim((string) ($fields['middle_name'] ?? '')) ?: null,
            'last_name' => trim($fields['last_name']),
            'suffix' => trim((string) ($fields['suffix'] ?? '')) ?: null,
            'email' => $email,
        ];
        // A different email than the one already confirmed needs a new code.
        if (! $change || $emailBefore !== $email) {
            $values += ['email_confirmed_at' => null, 'email_code_hash' => null, 'email_code_sent_at' => null];
        }

        $change = $change
            ? tap($change)->update($values)
            : OwnerIdentityChange::create($values + ['user_id' => $owner->id, 'status' => ChangeRequestStatus::Draft->value]);
        $change->setRelation('user', $owner);

        $sent = null;
        if ($change->emailChanges() && ! $change->email_confirmed_at) {
            $sent = $this->sendCode($change);
        }

        return response()->json([
            'data' => $this->present($change),
            'message' => $sent === false ? 'We could not send the code right now. Try "Send again" in a moment.' : null,
        ]);
    }

    public function resendCode(User $owner)
    {
        $change = $this->draftFor($owner);
        if (! $change->emailChanges() || $change->email_confirmed_at) {
            return response()->json(['message' => 'This email needs no code.'], 422);
        }
        if ($change->email_code_sent_at && $change->email_code_sent_at->diffInSeconds(now()) < self::RESEND_SECONDS) {
            return response()->json(['message' => 'Wait a minute before asking for another code.'], 429);
        }

        return $this->sendCode($change)
            ? response()->json(['message' => 'A new code is on its way.', 'data' => $this->present($change)])
            : response()->json(['message' => 'We could not send the code right now. Please try again in a moment.'], 503);
    }

    public function confirmEmail(User $owner, string $code)
    {
        $change = $this->draftFor($owner);
        if (! $change->email_code_hash || ! $change->email_code_sent_at) {
            return response()->json(['message' => 'Ask for a code first.'], 422);
        }
        if ($change->email_code_sent_at->copy()->addMinutes(self::CODE_MINUTES)->isPast()) {
            return response()->json(['message' => 'That code has run out. Send a new one.'], 422);
        }
        if (! Hash::check($code, $change->email_code_hash)) {
            return response()->json(['message' => 'That code is not right. Check the email and try again.'], 422);
        }

        $change->update(['email_confirmed_at' => now(), 'email_code_hash' => null]);

        return response()->json(['data' => $this->present($change)]);
    }

    public function saveId(User $owner, string $idType, UploadedFile $front, ?UploadedFile $back)
    {
        $change = $this->draftFor($owner);
        $folder = "verification/identity/{$owner->uuid}/changes/{$change->uuid}";

        $change->update([
            'id_type' => $idType,
            'id_document_front_path' => $this->documents->store($front, $folder),
            'id_document_back_path' => $back && OwnerIdentityVerification::backRequired($idType) ? $this->documents->store($back, $folder) : null,
        ]);

        return response()->json(['data' => $this->present($change)]);
    }

    /** @param UploadedFile[] $frames */
    public function saveFaceScan(User $owner, array $frames, array $sequence)
    {
        $change = $this->draftFor($owner);
        $paths = [];
        foreach ($frames as $frame) {
            $paths[] = $this->documents->store($frame, "verification/identity/{$owner->uuid}/changes/{$change->uuid}/face");
        }

        $change->update(['face_scan_paths' => $paths, 'liveness_sequence' => array_values($sequence)]);

        return response()->json(['data' => $this->present($change)]);
    }

    public function submit(User $owner, ?Request $request = null)
    {
        $change = $this->draftFor($owner);
        if ($change->emailChanges() && ! $change->email_confirmed_at) {
            return response()->json(['message' => 'Confirm your new email with the code first.'], 422);
        }
        if (! $change->hasIdDocuments()) {
            return response()->json(['message' => 'Add a photo of your ID first.'], 422);
        }
        if (empty($change->face_scan_paths)) {
            return response()->json(['message' => 'Take the face scan first.'], 422);
        }

        $change->update(['status' => ChangeRequestStatus::Pending->value, 'submitted_at' => now()]);
        $this->auditLogs->record($owner->id, 'owner_identity_changes', $change->id, 'Submit', ['status' => ChangeRequestStatus::Draft->value], ['status' => ChangeRequestStatus::Pending->value], $request);

        $who = trim("{$owner->first_name} {$owner->last_name}");
        foreach ($this->admins->allAdministrators() as $admin) {
            $this->notifications->create($admin->id, 'Owner Details Change', "{$who} asked to change their name or email. Their new ID and face scan are ready to check.", 'Verification', $change->uuid);
        }

        return response()->json(['data' => $this->present($change), 'message' => 'Sent to the SERVORA team.']);
    }

    // A draft or a request still waiting can be called off. It is kept, marked Cancelled.
    public function cancel(User $owner, ?Request $request = null)
    {
        $change = $this->openFor($owner);
        if (! $change) {
            return response()->json(['message' => 'There is no request to cancel.'], 422);
        }
        $before = $change->status;
        $change->update(['status' => ChangeRequestStatus::Cancelled->value, 'email_code_hash' => null]);
        $this->auditLogs->record($owner->id, 'owner_identity_changes', $change->id, 'Cancel', ['status' => $before], ['status' => ChangeRequestStatus::Cancelled->value], $request);

        return response()->json(['message' => 'Request cancelled. Your name and email stay as they are.']);
    }

    // ── System administrator ───────────────────────────────────────────

    public function adminList(string $status = ChangeRequestStatus::Pending->value)
    {
        $rows = OwnerIdentityChange::query()
            ->with(['user:id,uuid,first_name,middle_name,last_name,suffix,email', 'reviewer:id,first_name,last_name'])
            ->when($status !== 'All', fn ($q) => $q->where('status', $status), fn ($q) => $q->where('status', '!=', ChangeRequestStatus::Draft->value))
            ->orderByRaw("CASE WHEN status = 'Pending' THEN 0 ELSE 1 END")
            ->orderByDesc('submitted_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows->map(fn (OwnerIdentityChange $c) => $this->presentForAdmin($c, false))->values()]);
    }

    public function adminShow(string $uuid)
    {
        $change = OwnerIdentityChange::with(['user', 'reviewer:id,first_name,last_name'])->where('uuid', $uuid)->where('status', '!=', ChangeRequestStatus::Draft->value)->firstOrFail();

        return response()->json(['data' => $this->presentForAdmin($change, true)]);
    }

    public function approve(User $admin, string $uuid, ?Request $request = null)
    {
        return DB::transaction(function () use ($admin, $uuid, $request) {
            $change = OwnerIdentityChange::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($change->status !== ChangeRequestStatus::Pending->value) {
                return response()->json(['message' => 'This request has already been decided.'], 422);
            }
            $owner = User::findOrFail($change->user_id);
            $emailChanges = mb_strtolower($change->email) !== mb_strtolower((string) $owner->email);
            if ($emailChanges && User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($change->email)])->where('id', '!=', $owner->id)->exists()) {
                return response()->json(['message' => 'Another account now uses that email. Reject this request and ask the owner to send a new one.'], 422);
            }

            $before = $owner->only(['first_name', 'middle_name', 'last_name', 'suffix', 'email']);
            $owner->forceFill([
                'first_name' => $change->first_name,
                'middle_name' => $change->middle_name,
                'last_name' => $change->last_name,
                'suffix' => $change->suffix,
            ] + ($emailChanges ? ['email' => $change->email, 'email_verified_at' => $change->email_confirmed_at ?? now()] : []))->save();

            // The new ID and face scan become the ones on the account. The old
            // files stay where they are (on the earlier record and audit trail).
            $identity = OwnerIdentityVerification::where('user_id', $owner->id)->first();
            if ($identity) {
                $identity->update([
                    'id_type' => $change->id_type,
                    'id_document_front_path' => $change->id_document_front_path,
                    'id_document_back_path' => $change->id_document_back_path,
                    'face_scan_paths' => $change->face_scan_paths,
                    'liveness_sequence' => $change->liveness_sequence,
                    'liveness_result' => 'Passed',
                    'verified_by' => $admin->id,
                    'verified_at' => now(),
                ]);
            }

            $change->update(['status' => ChangeRequestStatus::Approved->value, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            $this->auditLogs->record($admin->id, 'users', $owner->id, 'Approve', $before, $owner->only(['first_name', 'middle_name', 'last_name', 'suffix', 'email']), $request);

            $this->notifications->create($owner->id, 'Name and Email Updated', $emailChanges
                ? "Your new details were approved. Sign in with {$change->email} from now on."
                : 'Your new name was approved and is now on your account.', 'Verification');

            return response()->json(['message' => 'Approved. The owner\'s account now has the new details.', 'data' => $this->presentForAdmin($change->fresh(['user', 'reviewer']), true)]);
        });
    }

    public function reject(User $admin, string $uuid, string $reason, ?Request $request = null)
    {
        return DB::transaction(function () use ($admin, $uuid, $reason, $request) {
            $change = OwnerIdentityChange::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($change->status !== ChangeRequestStatus::Pending->value) {
                return response()->json(['message' => 'This request has already been decided.'], 422);
            }
            $change->update(['status' => ChangeRequestStatus::Rejected->value, 'rejection_reason' => $reason, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            $this->auditLogs->record($admin->id, 'owner_identity_changes', $change->id, 'Reject', ['status' => ChangeRequestStatus::Pending->value], ['status' => ChangeRequestStatus::Rejected->value, 'rejection_reason' => $reason], $request);
            $this->notifications->create($change->user_id, 'Details Change Not Approved', "Your name or email change wasn't approved: {$reason}", 'Verification');

            return response()->json(['message' => 'Rejected. The owner was told why.', 'data' => $this->presentForAdmin($change->fresh(['user', 'reviewer']), true)]);
        });
    }

    // ── internals ──────────────────────────────────────────────────────

    // The table comes with a migration the owner runs; until then the screen
    // says it is not available, rather than failing.
    private function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('owner_identity_changes');
    }

    private function guard(User $owner): void
    {
        throw_unless($this->ready(), NotSetUp::class, 'Changing your name or email is not set up on this server yet.');
        Gate::forUser($owner)->authorize('changeOwnerDetails', SpaBusiness::class);
        $identity = OwnerIdentityVerification::where('user_id', $owner->id)->first();
        throw_unless($identity && $identity->status === VerificationStatus::Verified->value, ActionRefused::class, 'Finish verifying your identity first. You can correct your details there.');
    }

    private function openFor(User $owner): ?OwnerIdentityChange
    {
        return OwnerIdentityChange::where('user_id', $owner->id)->whereIn('status', OwnerIdentityChange::OPEN)->latest('id')->first()
            ?->setRelation('user', $owner);
    }

    private function draftFor(User $owner): OwnerIdentityChange
    {
        $this->guard($owner);
        $change = $this->openFor($owner);
        throw_unless($change, ActionRefused::class, 'Start by entering your new name or email.');
        throw_unless($change->status === ChangeRequestStatus::Draft->value, ActionRefused::class, 'Your request is with the SERVORA team. Cancel it first to change it.');

        return $change;
    }

    private function sendCode(OwnerIdentityChange $change): bool
    {
        $otp = (string) random_int(100000, 999999);
        $change->update(['email_code_hash' => Hash::make($otp), 'email_code_sent_at' => now()]);

        return $this->deliver($change->email, new IdentityChangeEmailOtpMail($otp, self::CODE_MINUTES), 'identity change email code');
    }

    private function present(OwnerIdentityChange $c): array
    {
        return [
            'uuid' => $c->uuid,
            'first_name' => $c->first_name,
            'middle_name' => $c->middle_name,
            'last_name' => $c->last_name,
            'suffix' => $c->suffix,
            'email' => $c->email,
            'email_changes' => $c->emailChanges(),
            'email_confirmed' => (bool) $c->email_confirmed_at,
            'email_code_sent_at' => $c->email_code_sent_at?->toIso8601String(),
            'id_type' => $c->id_type,
            'has_id' => $c->hasIdDocuments(),
            'has_face_scan' => ! empty($c->face_scan_paths),
            'status' => $c->status,
            'rejection_reason' => $c->status === ChangeRequestStatus::Rejected->value ? $c->rejection_reason : null,
            'submitted_at' => $c->submitted_at?->toIso8601String(),
            'reviewed_at' => $c->reviewed_at?->toIso8601String(),
        ];
    }

    private function presentForAdmin(OwnerIdentityChange $c, bool $withDocuments): array
    {
        $u = $c->user;
        $row = [
            'uuid' => $c->uuid,
            'status' => $c->status,
            'submitted_at' => $c->submitted_at?->toIso8601String(),
            'reviewed_at' => $c->reviewed_at?->toIso8601String(),
            'reviewed_by' => $c->reviewer ? trim("{$c->reviewer->first_name} {$c->reviewer->last_name}") : null,
            'rejection_reason' => $c->rejection_reason,
            'business_name' => $u ? \App\Models\SpaBusiness::where('owner_id', $u->id)->value('business_name') : null,
            'current' => $u ? [
                'name' => trim(implode(' ', array_filter([$u->first_name, $u->middle_name, $u->last_name, $u->suffix]))),
                'email' => $u->email,
            ] : null,
            'requested' => [
                'name' => trim(implode(' ', array_filter([$c->first_name, $c->middle_name, $c->last_name, $c->suffix]))),
                'first_name' => $c->first_name,
                'middle_name' => $c->middle_name,
                'last_name' => $c->last_name,
                'suffix' => $c->suffix,
                'email' => $c->email,
                'email_confirmed' => (bool) $c->email_confirmed_at,
            ],
            'id_type' => $c->id_type,
        ];

        if ($withDocuments) {
            $identity = $u ? OwnerIdentityVerification::where('user_id', $u->id)->first() : null;
            $row['documents'] = [
                'id_front_url' => $this->documents->signedUrl($c->id_document_front_path),
                'id_back_url' => $this->documents->signedUrl($c->id_document_back_path),
                'face_scan_urls' => array_map(fn ($p) => $this->documents->signedUrl($p), $c->face_scan_paths ?? []),
            ];
            // What was verified before, to compare the face and the ID with.
            $row['on_file'] = $identity ? [
                'id_type' => $identity->id_type,
                'id_front_url' => $this->documents->signedUrl($identity->id_document_front_path),
                'id_back_url' => $this->documents->signedUrl($identity->id_document_back_path),
                'face_scan_urls' => array_map(fn ($p) => $this->documents->signedUrl($p), $identity->face_scan_paths ?? []),
            ] : null;
        }

        return $row;
    }
}

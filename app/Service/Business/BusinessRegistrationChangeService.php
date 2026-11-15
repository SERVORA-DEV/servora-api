<?php

namespace App\Service\Business;

use App\Models\BusinessRegistrationChange;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\System\AdminUsersRepository;
use App\Services\DocumentUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Changing the company registration of a verified business: the DTI number,
// the registered business name, the registered owner and the certificate.
// Those were checked by the SERVORA team, so they are not edited in place:
// the owner sends the new details with the new certificate, and a system
// administrator checks them, as at the first verification. Until then the
// business keeps its registration and stays verified and open. Nothing is
// deleted: the old certificate stays on file, named on the request.
class BusinessRegistrationChangeService
{
    public function __construct(
        private DocumentUploadService $documents,
        private AuditLogRepository $auditLogs,
        private NotificationRepository $notifications,
        private AdminUsersRepository $admins,
    ) {}

    // ── Owner ──────────────────────────────────────────────────────────

    public function show(User $owner)
    {
        $business = $this->businessOf($owner);
        $ready = $this->ready();
        $open = $ready && $business ? $this->openFor($business) : null;
        $last = $ready && $business && ! $open
            ? BusinessRegistrationChange::where('spa_business_id', $business->id)->whereIn('status', ['Approved', 'Rejected'])->latest('reviewed_at')->first()
            : null;

        return response()->json(['data' => [
            'available' => $ready,
            // Only a verified registration is changed this way (before that it is edited directly).
            'can_request' => $ready && $business?->verification_status === 'Verified' && $owner->role === 'business_owner',
            'request' => $open ? $this->present($open) : null,
            'last' => $last ? $this->present($last) : null,
        ]]);
    }

    public function submit(User $owner, array $fields, UploadedFile $certificate, ?Request $request = null)
    {
        $business = $this->guard($owner);
        if ($this->openFor($business)) {
            return response()->json(['message' => 'A change is already with the SERVORA team. Cancel it first to send a new one.'], 422);
        }

        $type = $business->registration_document_type ?: 'DTI';
        $path = $this->documents->store($certificate, "verification/business/{$business->uuid}/changes");

        $change = BusinessRegistrationChange::create([
            'spa_business_id' => $business->id,
            'user_id' => $owner->id,
            'registration_document_type' => $type,
            'registration_number' => trim($fields['registration_number']),
            'registered_business_name' => trim($fields['registered_business_name']),
            'registered_owner_name' => $type === 'DTI' ? (trim((string) ($fields['registered_owner_name'] ?? '')) ?: null) : null,
            'registration_document_path' => $path,
            'note' => trim((string) ($fields['note'] ?? '')) ?: null,
            'previous' => $business->only(['registration_number', 'registered_business_name', 'registered_owner_name', 'registration_document_path']),
            'status' => 'Pending',
            'submitted_at' => now(),
        ]);

        $this->auditLogs->record($owner->id, 'business_registration_changes', $change->id, 'Submit', null, [
            'registration_number' => $change->registration_number,
            'registered_business_name' => $change->registered_business_name,
            'registered_owner_name' => $change->registered_owner_name,
        ], $request);

        foreach ($this->admins->allAdministrators() as $admin) {
            $this->notifications->create($admin->id, 'Registration Change', "{$business->business_name} sent new registration details and a new certificate to check.", 'Verification', $change->uuid);
        }

        return response()->json(['data' => $this->present($change), 'message' => 'Sent to the SERVORA team. Your current registration stays until they approve it.']);
    }

    // A request still waiting can be called off. It is kept, marked Cancelled.
    public function cancel(User $owner, ?Request $request = null)
    {
        $business = $this->guard($owner);
        $change = $this->openFor($business);
        if (! $change) {
            return response()->json(['message' => 'There is no request to cancel.'], 422);
        }
        $change->update(['status' => 'Cancelled']);
        $this->auditLogs->record($owner->id, 'business_registration_changes', $change->id, 'Cancel', ['status' => 'Pending'], ['status' => 'Cancelled'], $request);

        return response()->json(['message' => 'Request cancelled. Your registration stays as it is.']);
    }

    // ── System administrator ───────────────────────────────────────────

    public function adminList(string $status = 'Pending')
    {
        abort_unless($this->ready(), 503, 'Registration changes are not set up on this server yet.');
        $rows = BusinessRegistrationChange::query()
            ->with(['business:id,uuid,business_name,registration_number,registered_business_name,registered_owner_name', 'user:id,first_name,last_name,email', 'reviewer:id,first_name,last_name'])
            ->when($status !== 'All', fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'Pending' THEN 0 ELSE 1 END")
            ->orderByDesc('submitted_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows->map(fn (BusinessRegistrationChange $c) => $this->presentForAdmin($c, false))->values()]);
    }

    public function adminShow(string $uuid)
    {
        abort_unless($this->ready(), 503, 'Registration changes are not set up on this server yet.');
        $change = BusinessRegistrationChange::with(['business', 'user', 'reviewer:id,first_name,last_name'])->where('uuid', $uuid)->firstOrFail();

        return response()->json(['data' => $this->presentForAdmin($change, true)]);
    }

    public function approve(User $admin, string $uuid, ?Request $request = null)
    {
        abort_unless($this->ready(), 503, 'Registration changes are not set up on this server yet.');

        return DB::transaction(function () use ($admin, $uuid, $request) {
            $change = BusinessRegistrationChange::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($change->status !== 'Pending') {
                return response()->json(['message' => 'This request has already been decided.'], 422);
            }
            $business = SpaBusiness::findOrFail($change->spa_business_id);
            $fields = ['registration_number', 'registered_business_name', 'registered_owner_name', 'registration_document_path'];
            $before = $business->only($fields);

            // The new details and certificate become the business's. The old
            // certificate is not removed: it stays on file, named on this request.
            $business->forceFill([
                'registration_number' => $change->registration_number,
                'registered_business_name' => $change->registered_business_name,
                'registered_owner_name' => $change->registered_owner_name,
                'registration_document_path' => $change->registration_document_path,
                'verified_by' => $admin->id,
                'verified_at' => now(),
            ])->save();

            $change->update(['status' => 'Approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            $this->auditLogs->record($admin->id, 'spa_businesses', $business->id, 'Approve', $before, $business->only($fields), $request);
            $this->notifications->create($change->user_id, 'Registration Updated', 'Your new registration details were approved and are now on your business.', 'Verification');

            return response()->json(['message' => 'Approved. The business now has the new registration.', 'data' => $this->presentForAdmin($change->fresh(['business', 'user', 'reviewer']), true)]);
        });
    }

    public function reject(User $admin, string $uuid, string $reason, ?Request $request = null)
    {
        abort_unless($this->ready(), 503, 'Registration changes are not set up on this server yet.');

        return DB::transaction(function () use ($admin, $uuid, $reason, $request) {
            $change = BusinessRegistrationChange::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($change->status !== 'Pending') {
                return response()->json(['message' => 'This request has already been decided.'], 422);
            }
            $change->update(['status' => 'Rejected', 'rejection_reason' => $reason, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            $this->auditLogs->record($admin->id, 'business_registration_changes', $change->id, 'Reject', ['status' => 'Pending'], ['status' => 'Rejected', 'rejection_reason' => $reason], $request);
            $this->notifications->create($change->user_id, 'Registration Change Not Approved', "Your registration change wasn't approved: {$reason}", 'Verification');

            return response()->json(['message' => 'Rejected. The owner was told why.', 'data' => $this->presentForAdmin($change->fresh(['business', 'user', 'reviewer']), true)]);
        });
    }

    // ── internals ──────────────────────────────────────────────────────

    // The table comes with a migration the owner runs; until then the screen
    // says it is not available, rather than failing.
    private function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('business_registration_changes');
    }

    private function businessOf(User $owner): ?SpaBusiness
    {
        return SpaBusiness::where('owner_id', $owner->id)->first();
    }

    private function guard(User $owner): SpaBusiness
    {
        abort_unless($this->ready(), 503, 'Changing your registration is not set up on this server yet.');
        abort_unless($owner->role === 'business_owner', 403, 'Only the owner can do this.');
        $business = $this->businessOf($owner);
        abort_unless($business, 422, 'Register your business first.');
        abort_unless($business->verification_status === 'Verified', 422, 'Your registration is not verified yet. You can correct it on the verification page.');

        return $business;
    }

    private function openFor(SpaBusiness $business): ?BusinessRegistrationChange
    {
        return BusinessRegistrationChange::where('spa_business_id', $business->id)->where('status', 'Pending')->latest('id')->first();
    }

    private function present(BusinessRegistrationChange $c): array
    {
        return [
            'uuid' => $c->uuid,
            'registration_document_type' => $c->registration_document_type,
            'registration_number' => $c->registration_number,
            'registered_business_name' => $c->registered_business_name,
            'registered_owner_name' => $c->registered_owner_name,
            'note' => $c->note,
            'status' => $c->status,
            'rejection_reason' => $c->status === 'Rejected' ? $c->rejection_reason : null,
            'submitted_at' => $c->submitted_at?->toIso8601String(),
            'reviewed_at' => $c->reviewed_at?->toIso8601String(),
        ];
    }

    private function presentForAdmin(BusinessRegistrationChange $c, bool $withDocuments): array
    {
        $b = $c->business;
        $prev = $c->previous ?? [];
        $row = [
            'uuid' => $c->uuid,
            'status' => $c->status,
            'submitted_at' => $c->submitted_at?->toIso8601String(),
            'reviewed_at' => $c->reviewed_at?->toIso8601String(),
            'reviewed_by' => $c->reviewer ? trim("{$c->reviewer->first_name} {$c->reviewer->last_name}") : null,
            'rejection_reason' => $c->rejection_reason,
            'note' => $c->note,
            'business_uuid' => $b?->uuid,
            'business_name' => $b?->business_name,
            'owner' => $c->user ? ['name' => trim("{$c->user->first_name} {$c->user->last_name}"), 'email' => $c->user->email] : null,
            'document_type' => $c->registration_document_type,
            // What the business had when the request was sent.
            'current' => [
                'registration_number' => $prev['registration_number'] ?? null,
                'registered_business_name' => $prev['registered_business_name'] ?? null,
                'registered_owner_name' => $prev['registered_owner_name'] ?? null,
            ],
            'requested' => [
                'registration_number' => $c->registration_number,
                'registered_business_name' => $c->registered_business_name,
                'registered_owner_name' => $c->registered_owner_name,
            ],
        ];

        if ($withDocuments) {
            $row['documents'] = [
                'new_certificate_url' => $this->documents->signedUrl($c->registration_document_path),
                'old_certificate_url' => $this->documents->signedUrl($prev['registration_document_path'] ?? null),
            ];
        }

        return $row;
    }
}

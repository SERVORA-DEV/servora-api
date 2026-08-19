<?php

namespace App\Service\Business;

use App\Http\Resources\OwnerVerificationResource;
use App\Models\OwnerIdentityVerification;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\Business\OwnerVerificationRepository;
use App\Repository\SpaBusinessRepository;
use App\Repository\System\AdminUsersRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Services\DocumentUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class OwnerVerificationService
{
    public function __construct(
        private OwnerVerificationRepository $ownerVerificationRepository,
        private SpaBusinessRepository $spaBusinessRepository,
        private AdminUsersRepository $adminUsersRepository,
        private AuditLogRepository $auditLogRepository,
        private NotificationService $notificationService,
        private DocumentUploadService $documentUploadService,
        private UserRepository $userRepository,
    ) {}

    /**
     * A fresh account legitimately has no SpaBusiness yet — the Business
     * Ownership step's "details" sub-step (see saveBusinessDetails) is what
     * creates it. Unlike every other method here, this never 422s on a
     * missing business; it represents that state as an "empty" business
     * shape instead (see OwnerVerificationResource).
     */
    public function getStatus(User $user)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        $identity = $this->ownerVerificationRepository->findOrCreateIdentity($user);

        return new OwnerVerificationResource((object) [
            'business' => $business,
            'identity' => $identity,
            'owner' => $user,
        ]);
    }

    /**
     * First sub-step of Owner Identity — plain profile update, not part of
     * the identity verification state machine (no status guard: a user can
     * always correct their own name/contact info, unlike the ID/face-scan
     * artifacts below it). Replaces what the old, now-removed
     * OnboardingService did for the "personal" half.
     */
    public function savePersonalDetails(User $user, array $fields)
    {
        $this->userRepository->update($user, [
            'first_name' => $fields['first_name'],
            'middle_name' => $fields['middle_name'] ?? null,
            'last_name' => $fields['last_name'],
            'suffix' => $fields['suffix'] ?? null,
            'gender' => $fields['gender'],
            'birth_date' => $fields['birth_date'],
            'phone_number' => $fields['phone_number'],
            'profile_photo' => $fields['profile_photo'] ?? $user->profile_photo,
        ]);

        return $this->getStatus($user->fresh());
    }

    /**
     * First sub-step of Business Ownership — creates the SpaBusiness row on
     * the owner's first pass through (replacing what the old OnboardingService
     * did for the "business" half), or updates it on a later resubmission.
     * The type/registration-document sub-step (saveBusinessVerification)
     * requires this to have run first.
     */
    public function saveBusinessDetails(User $user, array $fields)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if ($business && ! in_array($business->verification_status, ['Unregistered', 'Rejected'], true)) {
            return response()->json([
                'message' => 'Business verification is already submitted or verified.',
            ], 422);
        }

        $payload = [
            'business_name' => $fields['business_name'],
            'business_email' => $fields['business_email'],
            'business_phone' => $fields['business_phone'],
            'business_description' => $fields['business_description'] ?? null,
        ];

        if ($business) {
            $this->spaBusinessRepository->update($business, $payload);
        } else {
            $payload['owner_id'] = $user->id;
            $payload['verification_status'] = 'Unregistered';
            $this->spaBusinessRepository->create($payload);
        }

        return $this->getStatus($user);
    }

    /**
     * Handles both the first-time draft save (status stays Unregistered
     * until submit()) and the "replace the rejected ID" half of resubmission
     * (spec §12) — the record only actually flips back to Pending once
     * BOTH the ID and the face scan have been resupplied (see
     * resolveIdentityIfComplete), so the owner always has to walk through
     * ID → face scan on resubmission, never just one or the other.
     */
    public function saveIdentityDocument(User $user, array $fields, UploadedFile $frontFile, UploadedFile $backFile, ?Request $request = null)
    {
        $identity = $this->ownerVerificationRepository->findOrCreateIdentity($user);

        if (! in_array($identity->status, ['Unregistered', 'Rejected'], true)) {
            return response()->json([
                'message' => 'Identity verification is already submitted or verified.',
            ], 422);
        }

        $oldValues = $identity->only(['id_type', 'status']);

        $frontPath = $this->documentUploadService->replace(
            $identity->id_document_front_path,
            $frontFile,
            "verification/identity/{$user->uuid}/id/front"
        );

        $backPath = $this->documentUploadService->replace(
            $identity->id_document_back_path,
            $backFile,
            "verification/identity/{$user->uuid}/id/back"
        );

        $identity = $this->ownerVerificationRepository->updateIdentity($user, [
            'id_type' => $fields['id_type'],
            'id_document_front_path' => $frontPath,
            'id_document_back_path' => $backPath,
        ]);

        $this->auditLogRepository->record(
            $user->id,
            'owner_identity_verifications',
            $identity->id,
            'Upload',
            $oldValues,
            $identity->only(['id_type', 'status']),
            $request
        );

        $this->resolveIdentityIfComplete($identity, $user, $request);

        return $this->getStatus($user);
    }

    /**
     * @param  UploadedFile[]  $frameFiles
     */
    public function saveFaceScan(User $user, array $fields, array $frameFiles, ?Request $request = null)
    {
        $identity = $this->ownerVerificationRepository->findOrCreateIdentity($user);

        if (! in_array($identity->status, ['Unregistered', 'Rejected'], true)) {
            return response()->json([
                'message' => 'Identity verification is already submitted or verified.',
            ], 422);
        }

        $oldValues = $identity->only(['liveness_result', 'status']);
        $oldFramePaths = $identity->face_scan_paths ?? [];

        $newFramePaths = [];
        foreach ($frameFiles as $frame) {
            $newFramePaths[] = $this->documentUploadService->store($frame, "verification/identity/{$user->uuid}/face");
        }

        foreach ($oldFramePaths as $oldPath) {
            $this->documentUploadService->delete($oldPath);
        }

        $identity = $this->ownerVerificationRepository->updateIdentity($user, [
            'face_scan_paths' => $newFramePaths,
            'liveness_sequence' => $fields['liveness_sequence'],
            // Completing the guided client-side sequence and successfully
            // producing frames is the only "pass" condition — no
            // server-side vision check exists. Real confidence comes from
            // the admin's manual review of these captures against the ID.
            'liveness_result' => 'Passed',
        ]);

        $this->auditLogRepository->record(
            $user->id,
            'owner_identity_verifications',
            $identity->id,
            'Upload',
            $oldValues,
            $identity->only(['liveness_result', 'status']),
            $request
        );

        $this->resolveIdentityIfComplete($identity, $user, $request);

        return $this->getStatus($user);
    }

    /**
     * After a resubmission-triggering save (ID or face scan) while the
     * identity is Rejected, only flips back to Pending once BOTH the ID
     * (front + back) and a passed face scan are present — a save that only
     * completes one half leaves the record Rejected so the *other* half's
     * save() still passes its Unregistered-or-Rejected guard. This is what
     * forces the owner through ID → face scan on every resubmission rather
     * than letting a fresh face scan pair with a stale ID (or vice versa).
     */
    private function resolveIdentityIfComplete(OwnerIdentityVerification $identity, User $user, ?Request $request): void
    {
        if ($identity->status !== 'Rejected') {
            return;
        }

        $complete = $identity->id_document_front_path
            && $identity->id_document_back_path
            && ! empty($identity->face_scan_paths)
            && $identity->liveness_result === 'Passed';

        if (! $complete) {
            return;
        }

        $oldValues = $identity->only(['status']);

        $identity = $this->ownerVerificationRepository->updateIdentity($user, [
            'status' => 'Pending',
            'rejection_reason' => null,
            'rejected_field' => null,
            'verified_by' => null,
            'verified_at' => null,
        ]);

        $this->auditLogRepository->record(
            $user->id,
            'owner_identity_verifications',
            $identity->id,
            'Replace',
            $oldValues,
            $identity->only(['status']),
            $request
        );

        // A rejection can only happen after a full submit() (which requires
        // a business to exist by then), so $business should always be
        // found here in practice — the null check is just defensive.
        $business = $this->spaBusinessRepository->findForUser($user);
        if ($business) {
            $this->notificationService->ownerIdentityResubmitted($business, $this->adminUsersRepository->allAdministrators());
        }
    }

    public function saveBusinessVerification(User $user, array $fields, UploadedFile $file, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'Please complete your business details first.'], 422);
        }

        if (! in_array($business->verification_status, ['Unregistered', 'Rejected'], true)) {
            return response()->json([
                'message' => 'Business verification is already submitted or verified.',
            ], 422);
        }

        $wasRejected = $business->verification_status === 'Rejected';
        $oldValues = $business->only(['business_type', 'verification_status']);

        $path = $this->documentUploadService->replace(
            $business->registration_document_path,
            $file,
            "verification/business/{$business->uuid}"
        );

        $payload = [
            'business_type' => $fields['business_type'],
            // DTI for Sole Proprietorship, SEC for Corporation/Partnership —
            // stored explicitly so a later business_type edit never
            // retroactively relabels an already-reviewed document.
            'registration_document_type' => $fields['business_type'] === 'Sole Proprietorship' ? 'DTI' : 'SEC',
            'registration_document_path' => $path,
            'registered_business_name' => $fields['registered_business_name'],
            'registration_number' => $fields['registration_number'],
            'registered_owner_name' => $fields['business_type'] === 'Sole Proprietorship'
                ? ($fields['registered_owner_name'] ?? null)
                : null,
            'authorized_representative_name' => $fields['business_type'] !== 'Sole Proprietorship'
                ? ($fields['authorized_representative_name'] ?? null)
                : null,
        ];

        if ($wasRejected) {
            $payload['verification_status'] = 'Pending';
            $payload['rejection_reason'] = null;
            $payload['verified_by'] = null;
            $payload['verified_at'] = null;
        }

        $business = $this->spaBusinessRepository->update($business, $payload);

        $this->auditLogRepository->record(
            $user->id,
            'spa_businesses',
            $business->id,
            $wasRejected ? 'Replace' : 'Upload',
            $oldValues,
            $business->only(['business_type', 'verification_status']),
            $request
        );

        if ($wasRejected) {
            $this->notificationService->ownerBusinessResubmitted($business, $this->adminUsersRepository->allAdministrators());
        }

        return $this->getStatus($user);
    }

    /**
     * The combined "Final Review -> Submit for Verification" action (spec
     * §10-11). Both parts must still be Unregistered — already-submitted
     * or already-decided verifications can't be resubmitted this way,
     * enforcing the "no repeated submission while pending" rule.
     */
    public function submit(User $user, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'Please complete your business details first.'], 422);
        }

        $identity = $this->ownerVerificationRepository->findOrCreateIdentity($user);

        if ($identity->status !== 'Unregistered' || $business->verification_status !== 'Unregistered') {
            return response()->json([
                'message' => 'Your identity and business verification have already been submitted.',
            ], 422);
        }

        if (! $user->first_name || ! $user->last_name || ! $user->gender || ! $user->birth_date || ! $user->phone_number) {
            return response()->json([
                'message' => 'Please complete your personal details before submitting.',
            ], 422);
        }

        if (! $identity->id_document_front_path || ! $identity->id_document_back_path || empty($identity->face_scan_paths) || $identity->liveness_result !== 'Passed') {
            return response()->json([
                'message' => 'Please complete your government ID upload and live face scan before submitting.',
            ], 422);
        }

        if (! $business->business_name || ! $business->business_email || ! $business->business_phone) {
            return response()->json([
                'message' => 'Please complete your business details before submitting.',
            ], 422);
        }

        $nameFieldMissing = match ($business->business_type) {
            'Sole Proprietorship' => empty($business->registered_owner_name),
            'Corporation', 'Partnership' => empty($business->authorized_representative_name),
            default => true,
        };

        if (! $business->registration_document_path || ! $business->registered_business_name || $nameFieldMissing) {
            return response()->json([
                'message' => 'Please complete your business registration details before submitting.',
            ], 422);
        }

        $identityOld = $identity->only(['status']);
        $businessOld = $business->only(['verification_status']);

        DB::transaction(function () use ($user, $business) {
            $this->ownerVerificationRepository->updateIdentity($user, ['status' => 'Pending']);
            $this->spaBusinessRepository->update($business, ['verification_status' => 'Pending']);
        });

        $identity = $this->ownerVerificationRepository->findOrCreateIdentity($user);
        $business = $business->fresh();

        $this->auditLogRepository->record(
            $user->id,
            'owner_identity_verifications',
            $identity->id,
            'Submit',
            $identityOld,
            $identity->only(['status']),
            $request
        );

        $this->auditLogRepository->record(
            $user->id,
            'spa_businesses',
            $business->id,
            'Submit',
            $businessOld,
            $business->only(['verification_status']),
            $request
        );

        $this->notificationService->ownerVerificationSubmitted($business, $this->adminUsersRepository->allAdministrators());

        return $this->getStatus($user);
    }
}

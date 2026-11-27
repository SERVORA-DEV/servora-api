<?php

namespace App\Service\Business;

use App\Enums\VerificationStatus;
use App\Exceptions\ActionRefused;
use App\Http\Resources\OwnerVerificationResource;
use App\Models\OwnerIdentityVerification;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\Business\OwnerVerificationRepository;
use App\Repository\SpaBusinessRepository;
use App\Repository\System\AdminUsersRepository;
use App\Repository\UserRepository;
use App\Service\Notifications\NotificationService;
use App\Service\Uploads\DocumentUploadService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

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
            'phone_number' => array_key_exists('phone_number', $fields) ? ($fields['phone_number'] ?: null) : $user->phone_number,
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

        if ($business && ! in_array($business->verification_status, [VerificationStatus::Unregistered->value, VerificationStatus::Rejected->value], true)) {
            return response()->json([
                'message' => 'Business verification is already submitted or verified.',
            ], 422);
        }

        $payload = [
            'business_name' => $fields['business_name'],
            'business_description' => $fields['business_description'] ?? null,
        ];

        if ($business) {
            $this->spaBusinessRepository->update($business, $payload);
        } else {
            $payload['owner_id'] = $user->id;
            $payload['verification_status'] = VerificationStatus::Unregistered->value;
            // The business starts with the owner's own sign-up email, already
            // proved by signing up. They can put another in Business Settings
            // (which is then confirmed by the button in an email).
            if (! \App\Models\SpaBusiness::withTrashed()->where('business_email', $user->email)->exists()) {
                $payload['business_email'] = $user->email;
                if (\App\Support\Branches\ContactEmail::ready()) {
                    $payload['business_email_verified_at'] = now();
                }
            }
            $this->spaBusinessRepository->create($payload);
        }

        return $this->getStatus($user);
    }

    public function saveIdentityDocument(User $user, array $fields, UploadedFile $frontFile, ?UploadedFile $backFile, ?Request $request = null)
    {
        if (! $backFile && OwnerIdentityVerification::backRequired($fields['id_type'])) {
            return response()->json([
                'message' => 'Upload the back of your ID too. Only a passport is a single photo.',
            ], 422);
        }

        return $this->saveIdentityEvidence($user, 'id_document', fn (Closure $store) => [
            'id_type' => $fields['id_type'],
            'id_document_front_path' => $store($frontFile, 'id/front'),
            'id_document_back_path' => $backFile ? $store($backFile, 'id/back') : null,
        ], $request);
    }

    /** @param UploadedFile[] $frameFiles */
    public function saveFaceScan(User $user, array $fields, array $frameFiles, ?Request $request = null)
    {
        return $this->saveIdentityEvidence($user, 'face_scan', fn (Closure $store) => [
            'face_scan_paths' => array_map(fn ($file) => $store($file, 'face'), $frameFiles),
            'liveness_sequence' => $fields['liveness_sequence'],
            // The guided capture is complete. The administrator still checks it against the ID.
            'liveness_result' => 'Passed',
        ], $request);
    }

    private function saveIdentityEvidence(User $user, string $field, Closure $upload, ?Request $request)
    {
        $identityId = $this->ownerVerificationRepository->findOrCreateIdentity($user)->id;
        $newPaths = [];
        $store = function (UploadedFile $file, string $folder) use ($user, &$newPaths): string {
            $path = $this->documentUploadService->store($file, "verification/identity/{$user->uuid}/{$folder}");
            $newPaths[] = $path;

            return $path;
        };

        try {
            DB::transaction(function () use ($user, $identityId, $field, $upload, $store, $request) {
                $identity = OwnerIdentityVerification::whereKey($identityId)->lockForUpdate()->firstOrFail();
                $rejected = $identity->status === VerificationStatus::Rejected->value;
                throw_unless($rejected || $identity->status === VerificationStatus::Unregistered->value,
                    ActionRefused::class, 'Your identity has already been sent for review or approved.');

                $requested = $identity->rejected_field ?? 'both';
                throw_if($rejected && $requested !== 'both' && $requested !== $field, ActionRefused::class,
                    $requested === 'face_scan' ? 'Please retake your live face scan.' : 'Please upload a new photo of your government ID.');

                $savingId = $field === 'id_document';
                $auditFields = [$savingId ? 'id_type' : 'liveness_result', 'status', 'rejected_field'];
                $oldValues = $identity->only($auditFields);
                $oldPaths = $savingId
                    ? [$identity->id_document_front_path, $identity->id_document_back_path]
                    : ($identity->face_scan_paths ?? []);

                $identity->fill($upload($store));
                if ($rejected) {
                    $this->advanceIdentityResubmission($identity, $field, $requested);
                }
                $identity->save();

                $this->auditLogRepository->record(
                    $user->id,
                    'owner_identity_verifications',
                    $identity->id,
                    $rejected ? 'Replace' : 'Upload',
                    $oldValues,
                    $identity->only($auditFields),
                    $request
                );

                $resubmitted = $rejected && $identity->status === VerificationStatus::Pending->value;
                DB::afterCommit(function () use ($oldPaths, $resubmitted, $user) {
                    $this->deleteIdentityFiles($oldPaths);

                    if ($resubmitted) {
                        try {
                            if ($business = $this->spaBusinessRepository->findForUser($user)) {
                                $this->notificationService->ownerIdentityResubmitted($business, $this->adminUsersRepository->allAdministrators());
                            }
                        } catch (Throwable $error) {
                            report($error);
                        }
                    }
                });
            });
        } catch (Throwable $error) {
            $this->deleteIdentityFiles($newPaths);
            throw $error;
        }

        return $this->getStatus($user);
    }

    private function advanceIdentityResubmission(OwnerIdentityVerification $identity, string $field, string $requested): void
    {
        // If both were requested, keep the other item outstanding even when the old file is still on record.
        if ($requested === 'both' || ! $identity->hasIdDocuments() || ! $identity->hasPassedFaceScan()) {
            $identity->rejected_field = $field === 'id_document' ? 'face_scan' : 'id_document';

            return;
        }

        $identity->fill([
            'status' => VerificationStatus::Pending->value,
            'rejection_reason' => null,
            'rejected_field' => null,
            'verified_by' => null,
            'verified_at' => null,
        ]);
    }

    private function deleteIdentityFiles(array $paths): void
    {
        foreach (array_filter($paths) as $path) {
            try {
                $this->documentUploadService->delete($path);
            } catch (Throwable $error) {
                report($error);
            }
        }
    }

    public function saveBusinessVerification(User $user, array $fields, ?UploadedFile $file, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'Please complete your business details first.'], 422);
        }

        if (! in_array($business->verification_status, [VerificationStatus::Unregistered->value, VerificationStatus::Rejected->value], true)) {
            return response()->json([
                'message' => 'Business verification is already submitted or verified.',
            ], 422);
        }

        $wasRejected = $business->verification_status === VerificationStatus::Rejected->value;
        $oldValues = $business->only(['business_type', 'verification_status']);

        // No new file: the details are being corrected, and the certificate on
        // file stays (OwnerBusinessVerificationRequest only lets that through
        // when there is one).
        if ($file) {
            $path = $this->documentUploadService->replace(
                $business->registration_document_path,
                $file,
                "verification/business/{$business->uuid}"
            );
        } elseif ($business->registration_document_path) {
            $path = $business->registration_document_path;
        } else {
            return response()->json(['message' => 'Upload your DTI Business Name Certificate.'], 422);
        }

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
            $payload['verification_status'] = VerificationStatus::Pending->value;
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

        if ($identity->status !== VerificationStatus::Unregistered->value || $business->verification_status !== VerificationStatus::Unregistered->value) {
            return response()->json([
                'message' => 'Your identity and business verification have already been submitted.',
            ], 422);
        }

        if (! $user->first_name || ! $user->last_name || ! $user->gender || ! $user->birth_date) {
            return response()->json([
                'message' => 'Please complete your personal details before submitting.',
            ], 422);
        }

        if (! $identity->hasIdDocuments() || ! $identity->hasPassedFaceScan()) {
            return response()->json([
                'message' => 'Please complete your government ID upload and live face scan before submitting.',
            ], 422);
        }

        if (! $business->business_name) {
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
            $this->ownerVerificationRepository->updateIdentity($user, ['status' => VerificationStatus::Pending->value]);
            $this->spaBusinessRepository->update($business, ['verification_status' => VerificationStatus::Pending->value]);
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

<?php

namespace App\Support;

use App\Models\SpaBusiness;

class OwnerVerificationChecklist
{
    public const REQUIREMENTS = [
        'identity' => [
            'id_valid' => 'Government ID is valid, complete, and readable.',
            'name_match' => 'Government ID name matches the account owner.',
            'face_match' => 'Government ID photo matches the live face captures.',
        ],
        'business' => [
            'registration_valid' => 'Submitted document is a valid DTI/SEC business registration.',
            'details_match' => 'Registered business information matches the submitted details.',
            'owner_authority' => 'Certificate owner matches the verified account owner or has valid supporting authorization.',
        ],
    ];

    public static function empty(string $part): array
    {
        return array_fill_keys(array_keys(self::REQUIREMENTS[$part]), ['state' => 'pending', 'reason' => '']);
    }

    public static function status(SpaBusiness $business, string $part): string
    {
        return $part === 'identity'
            ? ($business->owner?->ownerIdentityVerification?->status ?? 'Unregistered')
            : ($business->verification_status ?? 'Unregistered');
    }

    /** Bind manual work to the actual evidence and submitted facts, never signed URLs. */
    public static function fingerprint(SpaBusiness $business, string $part): string
    {
        $owner = $business->owner;
        $identity = $owner?->ownerIdentityVerification;
        $person = $owner?->only(['id', 'first_name', 'middle_name', 'last_name', 'suffix']);
        $identityEvidence = $identity?->only([
            'uuid', 'id_type', 'id_document_front_path', 'id_document_back_path',
            'face_scan_paths', 'liveness_sequence', 'liveness_result',
        ]);
        $evidence = $part === 'identity' ? $identityEvidence : [
            $business->only(['uuid', 'owner_id', 'business_name', 'business_type',
                'registration_document_type', 'registration_document_path', 'registration_number',
                'registered_business_name', 'registered_owner_name', 'authorized_representative_name']),
            $identityEvidence,
        ];

        return hash('sha256', json_encode([$person, $evidence], JSON_THROW_ON_ERROR));
    }

    public static function blockers(SpaBusiness $business, string $part): array
    {
        $issues = [];
        if (self::status($business, $part) !== 'Pending') {
            $issues[] = 'Only a pending verification can be approved.';
        }
        if (! $business->owner) {
            $issues[] = 'The application has no account owner.';
        }
        if ($part === 'identity') {
            $identity = $business->owner?->ownerIdentityVerification;
            if (! $identity?->id_type || ! $identity?->hasIdDocuments()) {
                $issues[] = 'All required government ID images must be submitted.';
            }
            if (empty($identity?->face_scan_paths)) {
                $issues[] = 'Live face captures must be submitted.';
            }
            if ($identity?->liveness_result !== 'Passed') {
                $issues[] = 'The submitted liveness result must be Passed.';
            }
        } else {
            if (! in_array($business->registration_document_type, ['DTI', 'SEC'], true)
                || ! $business->registration_document_path) {
                $issues[] = 'A DTI or SEC registration document must be submitted.';
            }
            if (! $business->registered_business_name || ! $business->registration_number
                || (! $business->registered_owner_name && ! $business->authorized_representative_name)) {
                $issues[] = 'The submitted registration details are incomplete.';
            }
        }
        return $issues;
    }

    public static function allPassed(string $part, array $checks): bool
    {
        foreach (self::REQUIREMENTS[$part] as $key => $_label) {
            if (($checks[$key]['state'] ?? null) !== 'pass') {
                return false;
            }
        }
        return true;
    }

    public static function failuresExplained(string $part, array $checks): bool
    {
        $hasFailure = false;
        foreach (self::REQUIREMENTS[$part] as $key => $_label) {
            if (($checks[$key]['state'] ?? null) === 'fail') {
                $hasFailure = true;
                if (trim($checks[$key]['reason'] ?? '') === '') {
                    return false;
                }
            }
        }
        return $hasFailure;
    }
}

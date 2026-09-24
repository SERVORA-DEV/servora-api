<?php

namespace App\Service\Business;

use App\Http\Resources\BusinessSettingsResource;
use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\SpaBusinessRepository;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

// Backs the owner's Business Settings pages (web /business/settings/business/*).
// Every write returns the full settings payload so the client can re-hydrate
// from what was actually stored.
class BusinessSettingsService
{
    // Registration details are what the Servora team reviews. Once a review
    // is under way or done they can only change through the verification
    // flow again — same gate OwnerVerificationService uses.
    private const LEGAL_EDITABLE_STATUSES = ['Unregistered', 'Rejected'];

    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private AuditLogRepository $auditLogRepository,
        private ImageUploadService $imageUploadService,
    ) {}

    public function show(User $user)
    {
        $business = $this->businessOf($user);
        if (! $business) {
            return $this->noBusiness();
        }

        return $this->respond($business);
    }

    public function updateIdentity(User $user, array $fields, ?UploadedFile $logo, bool $removeLogo, ?Request $request = null)
    {
        $business = $this->businessOf($user);
        if (! $business) {
            return $this->noBusiness();
        }

        $old = $business->only([...array_keys($fields), 'business_logo']);
        $payload = $fields;

        if ($logo) {
            $payload['business_logo'] = $this->imageUploadService->replace($business->business_logo, $logo, 'business-logos');
        } elseif ($removeLogo && $business->business_logo) {
            $this->imageUploadService->delete($business->business_logo);
            $payload['business_logo'] = null;
        }

        $business = $this->spaBusinessRepository->update($business, $payload);

        $this->audit($user, $business, $old, $business->only(array_keys($payload)), $request);

        return $this->respond($business);
    }

    public function updateLegal(User $user, array $fields, ?Request $request = null)
    {
        $business = $this->businessOf($user);
        if (! $business) {
            return $this->noBusiness();
        }

        if (! in_array($business->verification_status, self::LEGAL_EDITABLE_STATUSES, true)) {
            return response()->json([
                'message' => 'Your registration is '.strtolower((string) $business->verification_status).'. Contact Servora support to change it.',
            ], 422);
        }

        $payload = $fields;

        // business_type is the legal structure; keep it consistent with the
        // document type (DTI = sole proprietorship, SEC = corporation or
        // partnership) so the verification review never sees a mismatch.
        if ($fields['registration_document_type'] === 'DTI') {
            $payload['business_type'] = 'Sole Proprietorship';
        } elseif (! in_array($business->business_type, ['Corporation', 'Partnership'], true)) {
            $payload['business_type'] = 'Corporation';
        }

        $old = $business->only(array_keys($payload));
        $business = $this->spaBusinessRepository->update($business, $payload);

        $this->audit($user, $business, $old, $business->only(array_keys($payload)), $request);

        return $this->respond($business);
    }

    public function updateSection(User $user, string $section, array $fields)
    {
        $business = $this->businessOf($user);
        if (! $business) {
            return $this->noBusiness();
        }

        $settings = $this->settingsFor($business);
        $current = $settings->section($section);

        $settings->{$section} = $this->castLike(SpaBusinessSetting::DEFAULTS[$section], array_replace_recursive($current, $fields));
        $settings->save();

        return $this->respond($business->setRelation('settings', $settings));
    }

    public function settingsFor(SpaBusiness $business): SpaBusinessSetting
    {
        return $business->settings ?? $business->settings()->create([]);
    }

    private function businessOf(User $user): ?SpaBusiness
    {
        return $this->spaBusinessRepository->findByOwnerId($user->id);
    }

    private function respond(SpaBusiness $business)
    {
        $business->setRelation('settings', $this->settingsFor($business));

        return new BusinessSettingsResource($business);
    }

    private function noBusiness()
    {
        return response()->json(['message' => 'No spa business found for this account.'], 422);
    }

    // Validation lets numeric strings ("30") and "1"/"0" through; store them
    // as the same types the defaults use so readers never juggle both.
    private function castLike(array $defaults, array $values): array
    {
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            if (is_array($default)) {
                $values[$key] = $this->castLike($default, (array) $value);
            } elseif (is_bool($default)) {
                $values[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif ((is_int($default) || is_float($default)) && is_numeric($value)) {
                $values[$key] = $value + 0;
            }
        }

        return array_intersect_key($values, $defaults);
    }

    private function audit(User $user, SpaBusiness $business, array $old, array $new, ?Request $request): void
    {
        $changed = array_keys(array_diff_assoc(
            array_map(fn ($v) => (string) $v, $new),
            array_map(fn ($v) => (string) $v, array_intersect_key($old, $new)),
        ));

        if (! $changed) {
            return;
        }

        $this->auditLogRepository->record(
            $user->id,
            'spa_businesses',
            $business->id,
            'Update',
            array_intersect_key($old, array_flip($changed)),
            array_intersect_key($new, array_flip($changed)),
            $request,
        );
    }
}

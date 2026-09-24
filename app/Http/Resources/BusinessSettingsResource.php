<?php

namespace App\Http\Resources;

use App\Models\SpaBusinessSetting;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Everything the owner's Business Settings pages read, in one payload.
// Expects the `settings` relation to be loaded (BusinessSettingsService does).
class BusinessSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $settings = $this->settings;

        return [
            'uuid' => $this->uuid,

            'identity' => [
                'business_name' => $this->business_name,
                'legal_name' => $this->legal_name,
                'spa_type' => $this->spa_type,
                'tagline' => $this->tagline,
                'business_description' => $this->business_description,
                'business_email' => $this->business_email,
                'business_phone' => $this->business_phone,
                'head_office_address' => $this->head_office_address,
                'facebook_url' => $this->facebook_url,
                'instagram_handle' => $this->instagram_handle,
                'website_url' => $this->website_url,
                'business_logo_url' => ImageUploadService::url($this->business_logo),
            ],

            'legal' => [
                'business_type' => $this->business_type,
                'registration_document_type' => $this->registration_document_type,
                'registration_number' => $this->registration_number,
                'registered_business_name' => $this->registered_business_name,
                'registered_owner_name' => $this->registered_owner_name,
                'authorized_representative_name' => $this->authorized_representative_name,
                'verification_status' => $this->verification_status,
                'editable' => in_array($this->verification_status, ['Unregistered', 'Rejected'], true),
            ],

            'payments' => $settings->section('payments'),
            'staff_policy' => $settings->section('staff_policy'),
            'booking_defaults' => $settings->section('booking_defaults'),
            'notifications' => $settings->section('notifications'),

            // Starting permissions for new Manager / Front Officer accounts.
            // Every key the role can have is listed, so the UI knows the set.
            'role_permissions' => collect(SpaBusinessSetting::ACCOUNT_ROLES)
                ->mapWithKeys(fn ($role) => [$role => $settings->rolePermissions($role)])
                ->all(),
        ];
    }
}

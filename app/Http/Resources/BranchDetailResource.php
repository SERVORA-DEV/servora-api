<?php

namespace App\Http\Resources;

use App\Services\ImageUploadService;
use App\Support\BranchHoursCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Backs the unauthenticated GET /spas/{uuid} branch-detail endpoint. Same
// "only what's safe to show a stranger" rule as NearbySpaResource/
// PublicSpaBusinessResource: no email/phone/verification internals. The
// caller (SpaBranchService::publicShow) is expected to pass in the already
// Verified+Active $branch (with 'business' and 'schedules' eager-loaded)
// plus the separately-queried $services/$packages/$therapists collections,
// since those need their own availability filtering the branch model alone
// doesn't express.
class BranchDetailResource extends JsonResource
{
    public function __construct(
        $branch,
        private $services,
        private $packages,
        private $therapists,
    ) {
        parent::__construct($branch);
    }

    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'branch_name' => $this->branch_name,
            'formatted_address' => $this->formatted_address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'cover_photo_url' => ImageUploadService::url($this->cover_photo),

            'business' => [
                'uuid' => $this->business->uuid,
                'business_name' => $this->business->business_name,
                'business_logo_url' => ImageUploadService::url($this->business->business_logo),
                'description' => $this->business->business_description,
            ],

            'hours' => BranchHoursCalculator::resolve($this->schedules, Carbon::now()),

            'services' => $this->services->map(fn ($bs) => [
                'service_variant_uuid' => $bs->serviceVariant->uuid,
                'name' => $bs->serviceVariant->service->name,
                'duration_minutes' => $bs->serviceVariant->duration_minutes,
                'price' => (float) ($bs->custom_price ?? $bs->serviceVariant->price),
            ])->values(),

            'packages' => $this->packages->map(fn ($bp) => [
                'package_uuid' => $bp->package->uuid,
                'name' => $bp->package->name,
                'duration_minutes' => $bp->package->duration_minutes,
                'price' => (float) ($bp->custom_price ?? $bp->package->default_price),
            ])->values(),

            'therapists' => $this->therapists->map(fn ($staff) => [
                'uuid' => $staff->uuid,
                'first_name' => $staff->first_name,
                'last_name' => $staff->last_name,
                'role' => $staff->role,
            ])->values(),
        ];
    }
}

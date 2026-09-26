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
// doesn't express. $services arrives as flat BranchService rows (one per
// variant per branch) and is grouped by parent service here — see the
// 'services' key below.
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
            'cover_photo_url' => $this->resource->coverPhotoUrl(),
            // Set by ReviewRepository::attachRatings() in SpaBranchService::publicShow.
            'rating_avg' => $this->rating_avg,
            'rating_count' => (int) ($this->rating_count ?? 0),

            'business' => [
                'uuid' => $this->business->uuid,
                'business_name' => $this->business->business_name,
                'business_logo_url' => ImageUploadService::url($this->business->business_logo),
                'description' => $this->business->business_description,
            ],

            'hours' => BranchHoursCalculator::resolve($this->schedules, Carbon::now()),

            // What the owner set in Branch Settings → Marketplace. Additive:
            // the mobile app doesn't read these yet.
            'listing' => [
                'promo_text' => $this->promo_text,
                'highlights' => $this->highlights ?? [],
                'display' => $this->resource->displaySettings(),
                'photos' => $this->resource->photos->map(fn ($p) => [
                    'url' => ImageUploadService::url($p->path),
                    'is_cover' => (bool) $p->is_cover,
                ])->values(),
            ],
            'socials' => [
                'facebook_url' => $this->facebook_url,
                'instagram_handle' => $this->instagram_handle,
                'website_url' => $this->website_url,
            ],

            // One entry per parent Service, with its bookable durations nested
            // underneath — the mobile app renders a single card per service with
            // a duration dropdown, not one card per duration.
            //
            // Grouped from the branch_services rows rather than walking
            // $service->variants: a business can switch an individual variant off
            // for one branch (BranchService.is_available), so the relation would
            // advertise durations this branch doesn't actually offer. Same reason
            // custom_price is still read per row.
            'services' => $this->services
                ->filter(fn ($bs) => $bs->serviceVariant?->service !== null)
                ->groupBy(fn ($bs) => $bs->serviceVariant->service_id)
                ->map(function ($rows) {
                    $service = $rows->first()->serviceVariant->service;

                    return [
                        'service_uuid' => $service->uuid,
                        'name' => $service->name,
                        'description' => $service->description,
                        // Ascending by duration: that is the axis the app's
                        // dropdown labels, and sorting by price instead would let
                        // a custom_price override reorder it in a way that reads
                        // as a bug.
                        'variants' => $rows
                            ->sortBy(fn ($bs) => $bs->serviceVariant->duration_minutes)
                            ->map(fn ($bs) => [
                                'service_variant_uuid' => $bs->serviceVariant->uuid,
                                'duration_minutes' => (int) $bs->serviceVariant->duration_minutes,
                                'price' => (float) ($bs->custom_price ?? $bs->serviceVariant->price),
                            ])
                            ->values(),
                    ];
                })
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                // Load-bearing: groupBy keys by service_id and map/sortBy preserve
                // keys, so without this the JSON encodes as an object instead of
                // an array and the client parses no services at all.
                ->values(),

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

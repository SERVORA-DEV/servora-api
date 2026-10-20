<?php

namespace App\Http\Resources;

use App\Services\ImageUploadService;
use App\Support\BranchHoursCalculator;
use App\Models\SpaBusinessSetting;
use App\Support\ReminderPolicy;
use App\Support\SocialLinks;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Service\Business\MarketplaceReadiness;
use App\Service\Client\BookingPolicy;

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

    /** Whether the client is reminded of a booking here, and how many hours before (ReminderPolicy). */
    private function clientReminder(): array
    {
        $policy = ReminderPolicy::for($this->resource);

        return ['enabled' => ReminderPolicy::remindsClients($policy), 'hours' => $policy['reminder_timing']];
    }

    /** @return array{methods: list<array{key: string, label: string}>, gcash_number: ?string, paymaya_number: ?string} */
    private function acceptedPayments(): array
    {
        $settings = \App\Support\AppCache::businessSettings($this->spa_business_id)?->section('payments')
            ?? SpaBusinessSetting::DEFAULTS['payments'];

        $methods = [];
        foreach (['cash' => 'Cash', 'gcash' => 'GCash', 'paymaya' => 'Maya', 'card' => 'Card', 'bank_transfer' => 'Bank transfer'] as $key => $label) {
            if (! empty($settings["accept_{$key}"])) {
                $methods[] = ['key' => $key, 'label' => $label];
            }
        }
        $number = fn (string $key) => ! empty($settings["accept_{$key}"]) && is_string($settings["{$key}_number"] ?? null) && trim($settings["{$key}_number"]) !== ''
            ? trim($settings["{$key}_number"])
            : null;

        return ['methods' => $methods, 'gcash_number' => $number('gcash'), 'paymaya_number' => $number('paymaya')];
    }

    public function toArray(Request $request): array
    {
        // Branch Settings → Marketplace: without the rating badge the rating
        // isn't shared. Prices are always shown.
        $display = $this->resource->displaySettings();
        $showRating = $display['show_reviews'] && $display['show_rating_badge'];
        $price = fn ($value) => (float) $value;

        return [
            'uuid' => $this->uuid,
            'branch_name' => $this->branch_name,
            'formatted_address' => $this->formatted_address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'cover_photo_url' => $this->resource->coverPhotoUrl(),
            // Set by ReviewRepository::attachRatings() in SpaBranchService::publicShow.
            'rating_avg' => $showRating ? $this->rating_avg : null,
            'rating_count' => $showRating ? (int) ($this->rating_count ?? 0) : 0,
            'prices_hidden' => false,

            'business' => [
                'uuid' => $this->business->uuid,
                'business_name' => $this->business->business_name,
                'business_logo_url' => ImageUploadService::url($this->business->business_logo),
                'description' => $this->business->business_description,
                // The line under the name, the "Verified business" chip and
                // "N other locations" — shown on the app's branch page the
                // way the owner's preview shows them.
                'tagline' => $this->business->tagline,
                'verified' => $this->business->verification_status === 'Verified',
                'other_branches_count' => \App\Models\SpaBranch::where('spa_business_id', $this->spa_business_id)
                    ->where('id', '!=', $this->id)
                    ->where('verification_status', 'Verified')
                    ->where('operating_status', 'Active')
                    ->where('listing_visible', true)
                    ->count(),
            ],

            'hours' => BranchHoursCalculator::resolve($this->schedules, Carbon::now()),

            // Customer programs this branch offers (loyalty, vouchers,
            // discounts, memberships) — what a visitor can get here. A
            // signed-in client's own points/vouchers come from
            // GET client/spas/{uuid}/rewards.
            'programs' => $this->programs(),

            // What the owner set in Branch Settings → Marketplace. The flags in
            // 'display' are already applied above (prices, rating, therapists)
            // and tell the app which sections to leave out.
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
                // What the app opens: the branch's own link, else the
                // company's (SocialLinks). Each: url, label, source
                // ('branch' | 'company'); a kind with no link is absent.
                'links' => (object) SocialLinks::forBranch($this->resource),
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
                ->map(function ($rows) use ($price) {
                    $service = $rows->first()->serviceVariant->service;

                    return [
                        'service_uuid' => $service->uuid,
                        'name' => $service->name,
                        // For vouchers that are for a category of service.
                        'category' => $service->category,
                        'description' => $service->description,
                        // Bookable as a home visit (the owner's tick).
                        'home_service_available' => (bool) $service->home_service_available,
                        // Ascending by duration: that is the axis the app's
                        // dropdown labels, and sorting by price instead would let
                        // a custom_price override reorder it in a way that reads
                        // as a bug.
                        'variants' => $rows
                            ->sortBy(fn ($bs) => $bs->serviceVariant->duration_minutes)
                            ->map(fn ($bs) => [
                                'service_variant_uuid' => $bs->serviceVariant->uuid,
                                'duration_minutes' => (int) $bs->serviceVariant->duration_minutes,
                                'price' => $price($bs->custom_price ?? $bs->serviceVariant->price),
                            ])
                            ->values(),
                    ];
                })
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                // Load-bearing: groupBy keys by service_id and map/sortBy preserve
                // keys, so without this the JSON encodes as an object instead of
                // an array and the client parses no services at all.
                ->values(),

            // duration_minutes is what a booking of this package will take:
            // its fixed duration — the minutes each service has in it, plus
            // the add-ons that come with it (App\Support\PackagePlan).
            'packages' => $this->packages->map(function ($bp) use ($price) {
                $items = $bp->package->packageServiceItems->filter(fn ($i) => $i->theService() !== null)->values();

                return [
                    'package_uuid' => $bp->package->uuid,
                    'name' => $bp->package->name,
                    'duration_minutes' => $bp->package->totalMinutes(),
                    'price' => $price($bp->custom_price ?? $bp->package->default_price),
                    // Each service in it, with what it is worth in the package:
                    // a voucher for that service takes that much off.
                    'services' => $items->map(fn ($i) => [
                        'service_uuid' => $i->theService()->uuid,
                        'name' => $i->theService()->name,
                        'category' => $i->theService()->category,
                        'price' => $i->unitPrice(),
                    ])->values(),
                    'included_services' => $items->map(fn ($i) => $i->theService()->name)
                        ->merge($bp->package->includedAddons()->map(fn ($row) => $row->addon->name))
                        ->values(),
                    // Only when every service in it can be done at home.
                    'home_service_available' => $items->isNotEmpty()
                        && $items->every(fn ($i) => (bool) $i->theService()->home_service_available),
                ];
            })->values(),

            // The owner's booking rules for this branch (BookingPolicy) — the
            // app uses them for its calendar window and service limit.
            'booking_policy' => BookingPolicy::for($this->resource) + ['client_reminder' => $this->clientReminder()],

            // What this spa takes at the counter (Business Settings →
            // Payments), with the wallet numbers of the accepted ones.
            'payments' => $this->acceptedPayments(),

            // Whether this branch sends therapists out, the flat fee and how
            // far (km from the branch's pin) — HomeServiceRules.
            'home_service' => \App\Service\Business\HomeServiceRules::offer($this->resource),

            // False when the branch can't take a booking right now (no
            // therapist, no hours… — MarketplaceReadiness). The page still
            // opens for existing links; the app just won't offer booking.
            'accepting_bookings' => MarketplaceReadiness::isBookable($this->resource),

            'therapists' => $this->therapists->map(fn ($staff) => [
                'uuid' => $staff->uuid,
                'first_name' => $staff->first_name,
                'last_name' => $staff->last_name,
                'role' => $staff->role,
                // What they do, main one first (App\Support\StaffSpecialty) —
                // sent for the app to show; it doesn't yet.
                'specialties' => $staff->specialtyList(),
                // For the app's preferred-gender filter (App\Support\StaffGender).
                'gender' => $staff->gender,
            ])->values(),
        ];
    }

    private function programs(): array
    {
        $rewards = app(\App\Service\Business\ProgramRewardService::class);
        if (! $rewards->enabledFor($this->spa_business_id)) {
            return [];
        }
        $programs = app(\App\Service\Business\CustomerProgramService::class);

        return $rewards->offeredAt($this->spa_business_id, $this->id)
            ->sortBy(fn ($p) => array_search($p->type, ['loyalty', 'voucher', 'discount', 'membership'], true))
            ->map(fn ($p) => [
                'uuid' => $p->uuid,
                'type' => $p->type,
                'name' => $p->name,
                'summary' => $programs->summaryText($p),
                'condition' => $p->type === 'voucher' ? ['kind' => $p->condition_kind, 'value' => $p->condition_value] : null,
            ])->values()->all();
    }
}

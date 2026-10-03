<?php

namespace App\Service\Business;

use App\Models\User;
use App\Repository\ReviewRepository;
use App\Http\Resources\Client\PublicReviewResource;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Repository\System\AdminUsersRepository;
use App\Repository\AuditLogRepository;
use App\Http\Resources\SpaBranchResource;
use App\Http\Resources\NearbySpaResource;
use App\Http\Resources\BranchDetailResource;
use App\Http\Resources\PublicTherapistAvailabilityResource;
use App\Service\Client\BookingPolicy;
use App\Support\AppCache;
use App\Support\BranchHoursCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Service\NotificationService;
use App\Services\DocumentUploadService;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class SpaBranchService
{
    private SpaBranchRepository $spaBranchRepository;
    private SpaBusinessRepository $spaBusinessRepository;
    private AdminUsersRepository $adminUsersRepository;
    private AuditLogRepository $auditLogRepository;
    private NotificationService $notificationService;
    private ImageUploadService $imageUploadService;
    private DocumentUploadService $documentUploadService;
    private AppointmentAvailabilityService $availabilityService;

    public function __construct(
        SpaBranchRepository $spaBranchRepository,
        SpaBusinessRepository $spaBusinessRepository,
        AdminUsersRepository $adminUsersRepository,
        AuditLogRepository $auditLogRepository,
        NotificationService $notificationService,
        ImageUploadService $imageUploadService,
        DocumentUploadService $documentUploadService,
        AppointmentAvailabilityService $availabilityService
    ) {
        $this->spaBranchRepository = $spaBranchRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->adminUsersRepository = $adminUsersRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->notificationService = $notificationService;
        $this->imageUploadService = $imageUploadService;
        $this->documentUploadService = $documentUploadService;
        $this->availabilityService = $availabilityService;
    }

    // Backs GET /spas/nearby — public, no auth. radiusKm/limit are clamped
    // rather than trusted as-is so a caller can't force an unbounded scan
    // (e.g. radius_km=999999) via query params.
    public function nearby(float $lat, float $lng, ?float $radiusKm, ?int $limit)
    {
        $radiusKm = min(max($radiusKm ?? 15, 1), 100);
        $limit = min(max($limit ?? 20, 1), 50);

        // Cached for 2 minutes per ~100 m of location (AppCache). Any change
        // to any listed spa bumps the marketplace version, so edits show up
        // at once. Open-now is recomputed on every request below.
        $key = sprintf('nearby:%.3f:%.3f:%s:%d:m%d', $lat, $lng, $radiusKm, $limit, AppCache::version('marketplace'));
        $cached = AppCache::remember($key, 120, function () use ($lat, $lng, $radiusKm, $limit) {
            // The query already keeps only bookable branches (MarketplaceReadiness);
            // online booking lives in settings JSON, so that part is checked here.
            $branches = $this->spaBranchRepository->nearby($lat, $lng, $radiusKm, $limit)
                ->filter(fn ($b) => MarketplaceReadiness::acceptsOnlineBooking($b))
                ->values();
            app(ReviewRepository::class)->attachRatings($branches);

            return [
                'data' => NearbySpaResource::collection($branches)->resolve(request()),
                'schedules' => $branches->map(fn ($b) => $b->relationLoaded('schedules') ? $b->schedules : null)->all(),
            ];
        });

        $now = Carbon::now();
        $data = $cached['data'];
        foreach ($data as $i => $row) {
            $schedules = $cached['schedules'][$i] ?? null;
            if ($schedules !== null) {
                $hours = BranchHoursCalculator::resolve($schedules, $now);
                $data[$i]['is_open_now'] = $hours['is_open_now'];
                $data[$i]['opens_at'] = $hours['opens_at'];
                $data[$i]['closes_at'] = $hours['closes_at'];
            }
        }

        return response()->json(['data' => $data]);
    }

    // Backs GET /spas/{uuid} — public, no auth. publicFindByUuid() 404s for
    // anything not Verified+Active before we ever look up its services/
    // packages/therapists.
    public function publicShow(string $uuid)
    {
        // The page is the same for every visitor, so it's cached (AppCache)
        // under the business's version: any change the owner makes to the
        // branch, catalog, staff, programs or settings — or a new review —
        // gives it a new key. Opening hours' "open now" is recomputed per
        // request.
        $businessId = AppCache::remember("branch:{$uuid}:biz", 86400,
            fn () => DB::table('spa_branches')->where('uuid', $uuid)->value('spa_business_id'));
        if (! $businessId) {
            $this->spaBranchRepository->publicFindByUuid($uuid); // 404s

            return null;
        }

        $cached = AppCache::remember("spa:{$uuid}:v" . AppCache::businessVersion($businessId), 600, function () use ($uuid) {
            $branch = $this->spaBranchRepository->publicFindByUuid($uuid);
            app(ReviewRepository::class)->attachRatings([$branch]);

            $services = $this->spaBranchRepository->publicServicesForBranch($branch->id);
            $packages = $this->spaBranchRepository->publicPackagesForBranch($branch->id);
            // Branch Settings → Marketplace → Show therapist profiles: off keeps
            // the roster private, and bookings are auto-assigned.
            $therapists = $branch->displaySettings()['show_therapist_profiles']
                ? $this->spaBranchRepository->publicTherapistsForBranch($branch->id)
                : collect();

            return [
                'data' => (new BranchDetailResource($branch, $services, $packages, $therapists))->resolve(request()),
                'schedules' => $branch->schedules,
            ];
        });

        $data = $cached['data'];
        $data['hours'] = BranchHoursCalculator::resolve($cached['schedules'], Carbon::now());

        return response()->json(['data' => $data]);
    }

    // Backs GET /spas/{uuid}/therapists — public, no auth. Answers "which
    // of this branch's therapists can actually take a booking at this
    // date/time", which GET /spas/{uuid} can't: its 'therapists' key is
    // every active therapist, unconditionally, because it has no requested
    // window to judge them against.
    //
    // No new availability logic lives here — the two checks are the same
    // ones AppointmentService runs when assigning a therapist, in the same
    // order, so what the client is shown and what the booking endpoint
    // will accept can't drift apart. Schedule first: "not working then" is
    // a more useful thing to tell someone than "already booked", and a
    // therapist who isn't rostered can't be booked either way.
    public function publicTherapistAvailability(string $uuid, array $query)
    {
        $branch = $this->spaBranchRepository->publicFindByUuid($uuid, ['business', 'schedules']);

        $date = $query['date'];
        $time = $query['time'];
        $duration = (int) ($query['duration_minutes'] ?? 60);

        $therapists = $branch->displaySettings()['show_therapist_profiles']
            ? $this->spaBranchRepository->publicTherapistsForBranch($branch->id)
            : collect();

        $rows = $therapists->map(function ($staff) use ($date, $time, $duration) {
            $schedule = $this->availabilityService->staffMatchesSchedule($staff->id, $date, $time, $duration);

            if (! $schedule['ok']) {
                return ['staff' => $staff, 'available' => false, 'reason' => $schedule['reason']];
            }

            $booked = $this->availabilityService->isStaffAvailable($staff->id, $date, $time, $duration);

            if (! $booked['ok']) {
                return ['staff' => $staff, 'available' => false, 'reason' => $booked['reason']];
            }

            return ['staff' => $staff, 'available' => true, 'reason' => null];
        })->values();

        return PublicTherapistAvailabilityResource::collection($rows);
    }

    // Backs GET /spas/{uuid}/slots — public, no auth. A requested therapist
    // is looked up in this branch's public roster (unknown → 404, like
    // days-off). exclude_appointment_uuid only counts when it's the signed-
    // in client's own booking, so rescheduling doesn't collide with itself.
    public function publicSlots(string $uuid, array $query, ?User $user = null): array
    {
        $branch = $this->spaBranchRepository->publicFindByUuid($uuid, ['business', 'schedules']);

        // Not ready for bookings (no therapist, no hours…): offer no times,
        // with the same message the booking endpoint would give.
        if (! MarketplaceReadiness::isBookable($branch)) {
            $policy = BookingPolicy::for($branch);

            return ['data' => [
                'date' => $query['date'],
                'duration_minutes' => (int) $query['duration_minutes'],
                'closed_reason' => $policy['online_booking_enabled']
                    ? MarketplaceReadiness::NOT_BOOKABLE
                    : BookingPolicy::startTimeProblem($policy, BookingPolicy::now()),
                'slots' => [],
            ]];
        }

        $therapist = null;
        // With therapist profiles hidden a client can't have picked one, so
        // a stray therapist_uuid is ignored rather than narrowing the slots.
        if (! empty($query['therapist_uuid']) && $branch->displaySettings()['show_therapist_profiles']) {
            $therapist = $this->spaBranchRepository->publicTherapistsForBranch($branch->id)
                ->firstWhere('uuid', $query['therapist_uuid']);
            abort_if(! $therapist, 404, 'Therapist not found.');
        }

        $excludeId = null;
        if (! empty($query['exclude_appointment_uuid']) && $user) {
            $excludeId = \App\Models\Appointment::where('uuid', $query['exclude_appointment_uuid'])
                ->where('spa_branch_id', $branch->id)
                ->whereHas('client', fn ($q) => $q->where('user_id', $user->id))
                ->value('id');
        }

        return ['data' => app(\App\Service\Client\BookingSlotService::class)->slots(
            $branch,
            $query['date'],
            (int) $query['duration_minutes'],
            $therapist,
            $excludeId,
        )];
    }

    // Backs GET /spas/{uuid}/therapists/{staffUuid}/days-off — public, no
    // auth. Only dates leave here, never shift times, the same "safe for a
    // stranger" line PublicTherapistAvailabilityResource draws. The staff
    // member is looked up within this branch's public roster, so an inactive
    // therapist or one from another branch is a 404 rather than a leak of
    // someone else's schedule.
    public function publicTherapistDaysOff(string $uuid, string $staffUuid, array $query): array
    {
        $branch = $this->spaBranchRepository->publicFindByUuid($uuid, ['business', 'schedules']);

        $staff = $this->spaBranchRepository
            ->publicTherapistsForBranch($branch->id)
            ->firstWhere('uuid', $staffUuid);

        abort_if(! $staff, 404, 'Therapist not found.');

        return [
            'data' => [
                'days_off' => $this->availabilityService->staffDaysOff(
                    $staff->id,
                    $query['from'],
                    $query['to'],
                ),
            ],
        ];
    }

    // Scoped to what this user can see — every branch for business_owner,
    // only their own assigned branch for manager (see
    // SpaBusinessRepository::branchesForUser). paginateForBusiness() would
    // leak every branch in the business to a manager, who's only supposed
    // to manage their one branch.
    public function listSpaBranch(User $user, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $branchIds = $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
        $collection = $this->spaBranchRepository->paginateForBranches($branchIds, $perPage);
        return SpaBranchResource::collection($collection);
    }

    /**
     * spa_business_id always comes from the authenticated owner's own
     * business, never from the request body — otherwise any owner could
     * register a branch under someone else's business by editing the
     * payload. Location and the permit are separate wizard steps (see
     * saveLocation/savePermit below) — this only handles the Info step
     * (name/contact/photo).
     */
    public function createSpaBranch(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        if (isset($payload['cover_photo']) && $payload['cover_photo'] instanceof UploadedFile) {
            $payload['cover_photo'] = $this->imageUploadService->store(
                $payload['cover_photo'],
                "branches/{$business->uuid}"
            );
        } else {
            unset($payload['cover_photo']);
        }

        $payload['spa_business_id'] = $business->id;

        // A newly created branch isn't registered yet — the owner still has
        // to complete Location + Permit and submit() (see below) before it
        // can go to Pending.
        $payload['verification_status'] = 'Unregistered';
        $payload['operating_status'] = 'Active';

        $model = $this->spaBranchRepository->create($payload);
        return new SpaBranchResource($model);
    }

    // Same manager-vs-owner scoping as listSpaBranch above — a manager can
    // only look up their own branch by uuid, not any branch in the business.
    public function getSpaBranch(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $branchIds = $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
        $model = $this->spaBranchRepository->findByUuidForBranches($uuid, $branchIds);
        return new SpaBranchResource($model);
    }

    public function getSpaBranchByField(string $field, $value)
    {
        $model = $this->spaBranchRepository->findByField($field, $value);
        return new SpaBranchResource($model);
    }

    /**
     * Same ownership guard as create — findByUuidForBusiness() 404s before
     * update() ever runs if this uuid isn't one of this owner's branches, so
     * an owner can't edit (or reactivate/deactivate) someone else's branch
     * just by knowing its uuid.
     */
    public function updateSpaBranch(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $branch = $this->spaBranchRepository->findByUuidForBusiness($uuid, $business->id);

        if (isset($payload['cover_photo']) && $payload['cover_photo'] instanceof UploadedFile) {
            $payload['cover_photo'] = $this->imageUploadService->replace(
                $branch->cover_photo,
                $payload['cover_photo'],
                "branches/{$business->uuid}"
            );
        } else {
            unset($payload['cover_photo']);
        }

        $model = $this->spaBranchRepository->update($uuid, $payload);
        return new SpaBranchResource($model);
    }

    public function deleteSpaBranch(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $this->spaBranchRepository->findByUuidForBusiness($uuid, $business->id);
        $this->spaBranchRepository->delete($uuid);
        return true;
    }

    public function restoreSpaBranch(string $uuid)
    {
        $model = $this->spaBranchRepository->restore($uuid);
        return new SpaBranchResource($model);
    }

    /**
     * Location step of the branch wizard — saves the confirmed map pin plus
     * whatever address components the frontend resolved for it. Never
     * changes verification_status on its own (unlike the old
     * submitRegistration, which flipped straight to Pending off the pin
     * alone) — only submit() below does that, once Permit is complete too.
     * Same Unregistered/Rejected guard as every other draft-mutating step.
     */
    public function saveLocation(User $user, string $uuid, array $payload, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $branch = $this->spaBranchRepository->findByUuidForBusiness($uuid, $business->id);

        if (! in_array($branch->verification_status, ['Unregistered', 'Rejected'], true)) {
            return response()->json([
                'message' => 'This branch already has a registration request in review or approved.',
            ], 422);
        }

        $oldValues = $branch->only(['latitude', 'longitude', 'formatted_address']);

        $branch = $this->spaBranchRepository->update($uuid, [
            'latitude' => $payload['latitude'],
            'longitude' => $payload['longitude'],
            'formatted_address' => $payload['formatted_address'],
        ]);

        $this->auditLogRepository->record(
            $user->id,
            'spa_branches',
            $branch->id,
            'Update',
            $oldValues,
            $branch->only(['latitude', 'longitude', 'formatted_address']),
            $request
        );

        return new SpaBranchResource($branch);
    }

    /**
     * Permit step of the branch wizard — the branch's own Business/Mayor's
     * Permit, kept separate from the business-level DTI/SEC document (that
     * already verified the business itself; this proves the specific
     * location). Uses DocumentUploadService (private/authenticated), same
     * as the business registration document. Same Unregistered/Rejected
     * guard as saveLocation.
     */
    public function savePermit(User $user, string $uuid, array $payload, UploadedFile $permitFile, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $branch = $this->spaBranchRepository->findByUuidForBusiness($uuid, $business->id);

        if (! in_array($branch->verification_status, ['Unregistered', 'Rejected'], true)) {
            return response()->json([
                'message' => 'This branch already has a registration request in review or approved.',
            ], 422);
        }

        $oldValues = $branch->only(['permit_number', 'permit_confirmed']);

        $path = $this->documentUploadService->replace(
            $branch->permit_document_path,
            $permitFile,
            "verification/branch/{$branch->uuid}/permit"
        );

        $branch = $this->spaBranchRepository->update($uuid, [
            'permit_document_path' => $path,
            'permit_number' => $payload['permit_number'],
            'permit_business_name' => $payload['permit_business_name'],
            'permit_branch_location' => $payload['permit_branch_location'],
            'permit_issue_date' => $payload['permit_issue_date'],
            'permit_expiration_date' => $payload['permit_expiration_date'],
            'permit_confirmed' => true,
        ]);

        $this->auditLogRepository->record(
            $user->id,
            'spa_branches',
            $branch->id,
            'Upload',
            $oldValues,
            $branch->only(['permit_number', 'permit_confirmed']),
            $request
        );

        return new SpaBranchResource($branch);
    }

    /**
     * The combined "Review -> Submit for Verification" action. Only allowed
     * from Unregistered (first submission) or Rejected (owner fixed the
     * flagged step and is trying again) — a branch that's already
     * Pending/Verified/Suspended can't be resubmitted out from under the
     * admin's review. Requires Info + Location + Permit to all be complete,
     * mirroring OwnerVerificationService::submit()'s completeness checks.
     */
    public function submit(User $user, string $uuid, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $branch = $this->spaBranchRepository->findByUuidForBusiness($uuid, $business->id);

        if (! in_array($branch->verification_status, ['Unregistered', 'Rejected'], true)) {
            return response()->json([
                'message' => 'This branch already has a registration request in review or approved.',
            ], 422);
        }

        if (! $branch->branch_name) {
            return response()->json([
                'message' => 'Please complete the branch information before submitting.',
            ], 422);
        }

        if ($branch->latitude === null || $branch->longitude === null) {
            return response()->json([
                'message' => 'Please select this branch\'s location on the map before submitting.',
            ], 422);
        }

        if (! $branch->permit_document_path || ! $branch->permit_confirmed) {
            return response()->json([
                'message' => 'Please upload this branch\'s business permit before submitting.',
            ], 422);
        }

        $oldValues = $branch->only(['verification_status']);

        $branch = $this->spaBranchRepository->update($uuid, [
            'verification_status' => 'Pending',
            'rejection_reason' => null,
            'verified_by' => null,
            'verified_at' => null,
        ]);

        $this->auditLogRepository->record(
            $user->id,
            'spa_branches',
            $branch->id,
            'Submit',
            $oldValues,
            $branch->only(['verification_status']),
            $request
        );

        $this->notificationService->branchRegistrationSubmitted($branch, $this->adminUsersRepository->allAdministrators());

        return new SpaBranchResource($branch);
    }

    // Backs GET /spas/{uuid}/reviews — public, same Verified+Active+listed
    // guard as the branch page it's shown on.
    public function publicReviews(string $uuid)
    {
        $branch = $this->spaBranchRepository->publicFindByUuid($uuid, []);
        $display = $branch->displaySettings();

        // Branch Settings → Marketplace → Reviews: hidden reviews come back as
        // an empty page (the app drops the section), and without the rating
        // badge the average isn't shared either.
        if (! $display['show_reviews']) {
            return response()->json(['data' => [], 'meta' => ['rating_avg' => null, 'rating_count' => 0, 'hidden' => true]]);
        }

        $reviews = app(ReviewRepository::class);
        $summary = $display['show_rating_badge']
            ? ($reviews->ratingsForBranches([$branch->id])[$branch->id] ?? ['avg' => null, 'count' => 0])
            : ['avg' => null, 'count' => 0];

        return PublicReviewResource::collection($reviews->publishedForBranch($branch->id, sort: $display['review_sort']))
            ->additional(['meta' => ['rating_avg' => $summary['avg'], 'rating_count' => $summary['count'], 'hidden' => false]]);
    }
}

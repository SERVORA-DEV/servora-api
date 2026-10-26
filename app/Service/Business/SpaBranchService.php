<?php

namespace App\Service\Business;

use App\Models\SpaBranchPhoto;
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
use App\Support\BranchClosures;
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
        AppointmentAvailabilityService $availabilityService,
        private ManagerChangeNotifier $managerChanges,
        private \App\Service\PlanFitService $planFit,
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
            // The query already keeps only bookable branches (MarketplaceReadiness).
            $branches = $this->spaBranchRepository->nearby($lat, $lng, $radiusKm, $limit);
            app(ReviewRepository::class)->attachRatings($branches);
            if (BranchClosures::ready() && $branches->isNotEmpty()) {
                $branches->load(['closures' => BranchClosures::upcomingScope()]);
            }

            return [
                'data' => NearbySpaResource::collection($branches)->resolve(request()),
                'schedules' => $branches->map(fn ($b) => $b->relationLoaded('schedules') ? $b->schedules : null)->all(),
                'closures' => $branches->map(fn ($b) => $b->relationLoaded('closures') ? $b->closures : null)->all(),
            ];
        });

        $now = Carbon::now();
        $data = $cached['data'];
        foreach ($data as $i => $row) {
            $schedules = $cached['schedules'][$i] ?? null;
            if ($schedules !== null) {
                $hours = BranchHoursCalculator::resolve($schedules, $now, $cached['closures'][$i] ?? null);
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
            // Branch Settings → Marketplace → Show therapist profiles, or the
            // booking rule Client Can Choose Staff, off: the roster stays
            // private and the app offers no picker.
            $therapists = BookingPolicy::clientMayChooseTherapist($branch)
                ? $this->spaBranchRepository->publicTherapistsForBranch($branch->id)
                : collect();

            return [
                'data' => (new BranchDetailResource($branch, $services, $packages, $therapists))->resolve(request()),
                'schedules' => $branch->schedules,
                'closures' => BranchClosures::upcoming($branch->id),
            ];
        });

        // A temporary closure: the branch reads as closed while one runs, and
        // the page carries a notice for it (or for one coming up soon).
        $now = Carbon::now();
        $closures = $cached['closures'] ?? collect();
        $data = $cached['data'];
        $data['hours'] = BranchHoursCalculator::resolve($cached['schedules'], $now, $closures);
        $data['closure'] = BranchClosures::notice($closures, $now);

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

        $therapists = $this->choosableTherapists($branch, $query);

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

        // Who isn't free then, and when they next are that day.
        $busyIds = $rows->where('available', false)->map(fn ($row) => $row['staff']->id)->all();
        if ($busyIds) {
            $next = app(\App\Service\Client\BookingSlotService::class)->nextFreeStarts($branch, $date, $time, $duration, $busyIds);
            $rows = $rows->map(fn ($row) => $row['available'] ? $row : $row + ['next' => $next[$row['staff']->id] ?? null])->values();
        }

        return PublicTherapistAvailabilityResource::collection($rows);
    }

    // The therapists a client may pick here (none when the spa doesn't let
    // clients choose), narrowed to those who can perform what is being booked.
    private function choosableTherapists(\App\Models\SpaBranch $branch, array $query): \Illuminate\Support\Collection
    {
        $therapists = BookingPolicy::clientMayChooseTherapist($branch)
            ? $this->spaBranchRepository->publicTherapistsForBranch($branch->id)
            : collect();

        $serviceIds = $this->therapistServiceIds($query);
        if ($serviceIds->isNotEmpty()) {
            $qualifications = app(\App\Repository\Business\StaffServiceRepository::class);
            $therapists = $therapists->filter(
                fn ($staff) => $serviceIds->every(fn ($id) => $qualifications->isQualified($staff->id, $id))
            )->values();
        }

        return $therapists;
    }

    // Backs GET /spas/{uuid}/therapists/free-times — public, no auth. For the
    // booking flow's staff step, which comes before the date: each therapist
    // who can do what is being booked, with the first day from `from` (within
    // `days`) they have time free for the whole visit, and that day's free
    // stretches (BookingSlotService::freeWindows). Bookable time only — the
    // same answer the slots give — never their shift itself.
    public function publicTherapistFreeTimes(string $uuid, array $query): array
    {
        $branch = $this->spaBranchRepository->publicFindByUuid($uuid, ['business', 'schedules']);
        $therapists = $this->choosableTherapists($branch, $query);
        $duration = (int) ($query['duration_minutes'] ?? 60);
        $from = \Carbon\Carbon::parse($query['from'] ?? now()->toDateString());
        $days = (int) ($query['days'] ?? 7);

        $found = $therapists->mapWithKeys(fn ($staff) => [$staff->id => ['date' => null, 'windows' => []]])->all();
        if ($therapists->isNotEmpty() && MarketplaceReadiness::isBookable($branch)) {
            $slots = app(\App\Service\Client\BookingSlotService::class);
            $policy = BookingPolicy::for($branch);
            $left = $therapists->pluck('id')->all();
            for ($i = 0; $i < $days && $left; $i++) {
                $date = $from->copy()->addDays($i);
                // Past how far ahead the spa takes bookings: nothing later is either.
                $problem = BookingPolicy::startTimeProblem($policy, $date->copy()->endOfDay());
                if ($problem && ! str_starts_with($problem, 'Please book at least')) {
                    break;
                }
                foreach ($slots->freeWindows($branch, $date->toDateString(), $duration, $left) as $id => $windows) {
                    if ($windows) {
                        $found[$id] = ['date' => $date->toDateString(), 'windows' => $windows];
                        $left = array_values(array_diff($left, [$id]));
                    }
                }
            }
        }

        return ['data' => $therapists->map(fn ($staff) => ['uuid' => $staff->uuid] + $found[$staff->id])->values()->all()];
    }

    // The services (ids) a therapist has to be qualified for to take a booking
    // of these variants / this package — only those someone performs; a
    // facility-based one needs no therapist.
    private function therapistServiceIds(array $query): \Illuminate\Support\Collection
    {
        $services = collect();

        if (! empty($query['service_variant_uuids'])) {
            \App\Models\ServiceVariant::with('service')->whereIn('uuid', $query['service_variant_uuids'])->get()
                ->each(fn ($variant) => $services->push($variant->service));
        }
        if (! empty($query['package_uuid'])) {
            \App\Models\Package::where('uuid', $query['package_uuid'])->first()
                ?->packageServiceItems()->with('service')->get()
                ->each(fn ($item) => $services->push($item->service));
        }

        return $services->filter()
            ->filter(fn ($service) => \App\Support\ServiceKind::needsTherapist(\App\Support\ServiceKind::of($service->service_type)))
            ->pluck('id')->unique()->values();
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
            return ['data' => [
                'date' => $query['date'],
                'duration_minutes' => (int) $query['duration_minutes'],
                'closed_reason' => MarketplaceReadiness::NOT_BOOKABLE,
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

        $branchIds = $this->spaBusinessRepository->branchIdsForUser($user);
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

        // The plan's branch limit (branches on hold don't count). A business
        // with no live plan yet is still being set up and isn't limited here.
        $plan = $this->planFit->currentPlan($business);
        if (! $this->planFit->hasRoomForBranch($business, $plan)) {
            return response()->json([
                'message' => "Your {$plan->name} plan allows " . ((int) $plan->max_branches === 1 ? '1 branch' : "{$plan->max_branches} branches") . '. Upgrade to add another.',
                'code' => 'PLAN_LIMIT',
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

        // An email given with the branch is confirmed like one set later (the
        // registration itself no longer asks for it).
        if (! empty($payload['email'])) {
            \App\Support\ContactEmail::afterSave($model, null);
            $model->refresh();
        }

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

        $branchIds = $this->spaBusinessRepository->branchIdsForUser($user);
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

        // On hold by the plan: it comes back through bringBack() (which
        // checks there is room), not by flipping the ordinary on/off switch.
        if ($branch->plan_hold_at && ($payload['operating_status'] ?? null) === 'Active') {
            return response()->json([
                'message' => "{$branch->branch_name} is on hold because your plan has no room for it. Upgrade, or bring it back once there is room.",
                'code' => 'PLAN_HOLD',
            ], 422);
        }

        if (isset($payload['cover_photo']) && $payload['cover_photo'] instanceof UploadedFile) {
            $previous = $branch->cover_photo;
            $payload['cover_photo'] = $this->imageUploadService->replace(
                $previous,
                $payload['cover_photo'],
                "branches/{$business->uuid}"
            );
            // The old image is gone from storage. If the gallery had taken it
            // in (BranchSettingsService::adoptBranchPhoto), point that photo
            // at the new image instead of leaving a broken one.
            if ($previous) {
                SpaBranchPhoto::where('spa_branch_id', $branch->id)->where('path', $previous)
                    ->update(['path' => $payload['cover_photo']]);
            }
        } else {
            unset($payload['cover_photo']);
        }

        $wasStatus = $branch->operating_status;
        $wasEmail = $branch->email;

        $model = $this->spaBranchRepository->update($uuid, $payload);

        // A new email is confirmed by the button in the email Servora sends to it
        // (unless it is the owner's own sign-up address).
        if (array_key_exists('email', $payload)
            && \App\Support\ContactEmail::normalize($payload['email']) !== \App\Support\ContactEmail::normalize($wasEmail)) {
            \App\Support\ContactEmail::afterSave($model, $wasEmail);
            $model->refresh();
        }

        // Activate / Deactivate: the branch's manager should know.
        if (isset($payload['operating_status']) && $payload['operating_status'] !== $wasStatus) {
            $this->managerChanges->tell(
                $user, $model, ManagerChangeNotifier::HOURS, 'Branch Status Changed',
                $payload['operating_status'] === 'Active'
                    ? "switched {$model->branch_name} back on. Clients can find and book it again."
                    : "switched {$model->branch_name} off. Clients can't find or book it until it is switched back on.",
            );
        }

        return new SpaBranchResource($model);
    }

    // POST business/branch/{uuid}/bring-back — switch a branch the plan put
    // on hold back on, when the plan has room for it again.
    public function bringBack(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branch = $this->spaBranchRepository->findByUuidForBusiness($uuid, $business->id);
        if ($why = $this->planFit->bringBackBranch($business, $branch)) {
            return response()->json(['message' => $why, 'code' => 'PLAN_LIMIT'], 422);
        }

        return new SpaBranchResource($branch->fresh());
    }

    public function deleteSpaBranch(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $branch = $this->spaBranchRepository->findByUuidForBusiness($uuid, $business->id);

        // Deleting it takes its visits and bills out of reach: an unpaid bill could
        // never be collected. Settle those first, or switch the branch off instead.
        $open = \App\Models\Appointment::where('spa_branch_id', $branch->id)
            ->whereIn('status', [\App\Models\Appointment::STATUS_SCHEDULED, \App\Models\Appointment::STATUS_CHECKED_IN, \App\Models\Appointment::STATUS_IN_SERVICE])
            ->count();
        $unpaid = \App\Models\Billing::where('spa_branch_id', $branch->id)
            ->whereNotIn('status', ['Paid', 'Cancelled', 'Refunded'])
            ->count();
        if ($open > 0 || $unpaid > 0) {
            $parts = array_filter([
                $open > 0 ? $open . ' open ' . ($open === 1 ? 'booking' : 'bookings') : null,
                $unpaid > 0 ? $unpaid . ($unpaid === 1 ? ' unpaid bill' : ' unpaid bills') : null,
            ]);

            return response()->json([
                'message' => "{$branch->branch_name} still has " . implode(' and ', $parts) . ". Finish or cancel them and collect the payments before deleting it, or switch the branch off instead.",
            ], 422);
        }

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
    /**
     * "Resubmit documents" on a verified branch: its location and permit are
     * to be changed, so it goes back to the registration steps (Servora checks it
     * again when it is submitted). Everything on file stays, the name included.
     * While it is in that state it is not Verified, so it is not listed or bookable
     * until it is approved again.
     */
    public function startReverification(User $user, string $uuid, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $branch = $this->spaBranchRepository->findByUuidForBusiness($uuid, $business->id);

        if ($branch->verification_status !== 'Verified') {
            return response()->json([
                'message' => $branch->verification_status === 'Pending'
                    ? 'This branch is being reviewed already. Its documents can change once that is done.'
                    : 'This branch is already open for changes.',
            ], 422);
        }

        $branch = $this->spaBranchRepository->update($uuid, [
            'verification_status' => 'Unregistered',
            'verified_by' => null,
            'verified_at' => null,
        ]);

        $this->auditLogRepository->record(
            $user->id,
            'spa_branches',
            $branch->id,
            'Update',
            ['verification_status' => 'Verified'],
            ['verification_status' => 'Unregistered'],
            $request
        );

        return new SpaBranchResource($branch);
    }

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

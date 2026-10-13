<?php

namespace App\Service\Business;

use App\Http\Resources\StaffScheduleResource;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\StaffScheduleChangeRequest;
use App\Models\User;
use App\Repository\Business\StaffRepository;
use App\Repository\Business\StaffScheduleRepository;
use App\Repository\SpaBusinessRepository;
use App\Service\NotificationService;
use Illuminate\Support\Facades\DB;

class StaffScheduleService
{
    private StaffScheduleRepository $staffScheduleRepository;
    private StaffRepository $staffRepository;
    private SpaBusinessRepository $spaBusinessRepository;

    public function __construct(
        StaffScheduleRepository $staffScheduleRepository,
        StaffRepository $staffRepository,
        SpaBusinessRepository $spaBusinessRepository,
        private NotificationService $notificationService,
        private ManagerChangeNotifier $managerChanges,
    ) {
        $this->staffScheduleRepository = $staffScheduleRepository;
        $this->staffRepository = $staffRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
    }

    // Same branch-scoping convention as StaffService::branchIds — a manager
    // only ever reaches their own branch's staff.
    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchIdsForUser($user);
    }

    public function getSchedule(User $user, string $staffUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $staff = $this->staffRepository->findByUuidForBranches($staffUuid, $this->branchIds($user));

        return StaffScheduleResource::collection($this->staffScheduleRepository->currentForStaff($staff->id))
            ->additional(['pending_request' => $this->present($this->pendingFor($staff->id))]);
    }

    // Days omitted from the payload are left/made "not set" (no row at all) —
    // a whole-set replace, so omission is meaningful, same as
    // StaffService::updateServices' empty array meaning "no restrictions
    // configured". is_day_off always wins over any shift times the
    // client sends for that day, matching how
    // AppointmentAvailabilityService::staffMatchesSchedule already treats
    // is_day_off as short-circuiting those checks.
    //
    // With Staff Policies → "Owner approval for schedule changes" on, a
    // MANAGER's save doesn't touch the schedule: it becomes a request the
    // owner approves or rejects (approve() / reject() below). The owner's own
    // save always applies at once.
    public function updateSchedule(User $user, string $staffUuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $staff = $this->staffRepository->findByUuidForBranches($staffUuid, $this->branchIds($user));

        $rows = collect($payload['days'] ?? [])->map(function (array $day) {
            $isDayOff = (bool) ($day['is_day_off'] ?? false);

            return [
                'day_of_week' => $day['day_of_week'],
                'is_day_off' => $isDayOff,
                'start_time' => $isDayOff ? null : ($day['start_time'] ?? null),
                'end_time' => $isDayOff ? null : ($day['end_time'] ?? null),
            ];
        })->all();

        if ($this->needsApproval($business, $user)) {
            $request = DB::transaction(function () use ($business, $staff, $user, $rows) {
                // One open request per person: a newer one replaces it.
                $this->closePending($staff->id, StaffScheduleChangeRequest::SUPERSEDED);

                return StaffScheduleChangeRequest::create([
                    'staff_id' => $staff->id,
                    'spa_business_id' => $business->id,
                    'spa_branch_id' => $staff->spa_branch_id,
                    'requested_by_user_id' => $user->id,
                    'days' => $rows,
                    'status' => StaffScheduleChangeRequest::PENDING,
                ]);
            });

            if ($business->owner) {
                $this->notificationService->scheduleChangeRequested($business->owner, $this->nameOf($staff), $this->userName($user));
            }

            return StaffScheduleResource::collection($this->staffScheduleRepository->currentForStaff($staff->id))
                ->additional([
                    'pending' => true,
                    'message' => 'Sent to the owner for approval. The schedule changes once they approve it.',
                    'pending_request' => $this->present($request),
                ])
                ->response()
                ->setStatusCode(202);
        }

        DB::transaction(function () use ($staff, $rows) {
            $this->staffScheduleRepository->replaceCurrentForStaff($staff->id, $rows);
            // Whatever was waiting was written against the old schedule.
            $this->closePending($staff->id, StaffScheduleChangeRequest::SUPERSEDED);
        });

        // Applied at once (no approval needed): an owner who asked to hear
        // about staff changes is told. With approval on they were asked above.
        $this->managerChanges->tell(
            $user, fn () => $staff->branch, ManagerChangeNotifier::STAFF, 'Employee Schedule Changed',
            fn () => 'changed ' . $this->nameOf($staff) . "'s weekly schedule at {$staff->branch->branch_name}.",
            reference: $staff->uuid,
        );

        return StaffScheduleResource::collection($this->staffScheduleRepository->currentForStaff($staff->id))
            ->additional(['pending' => false, 'pending_request' => null]);
    }

    // ── Schedule change requests ────────────────────────────────────────

    // Every request still waiting, across the caller's branches.
    public function pendingRequests(User $user)
    {
        $requests = StaffScheduleChangeRequest::with(['staff', 'branch', 'requester.staff'])
            ->whereIn('spa_branch_id', $this->branchIds($user))
            ->where('status', StaffScheduleChangeRequest::PENDING)
            ->latest()
            ->get();

        return response()->json(['data' => $requests->map(fn ($request) => $this->present($request))->values()]);
    }

    public function approve(User $user, string $uuid)
    {
        if ($user->role !== 'business_owner') {
            return response()->json(['message' => 'Only the owner can approve a schedule change.'], 403);
        }

        $request = $this->findPending($user, $uuid);
        if (! $request) {
            return response()->json(['message' => 'This request has already been decided or withdrawn.'], 422);
        }

        DB::transaction(function () use ($request, $user) {
            $this->staffScheduleRepository->replaceCurrentForStaff($request->staff_id, $request->days ?? []);
            $request->update([
                'status' => StaffScheduleChangeRequest::APPROVED,
                'decided_by_user_id' => $user->id,
                'decided_at' => now(),
            ]);
        });

        if ($request->requester) {
            $this->notificationService->scheduleChangeDecided($request->requester, $this->nameOf($request->staff), true, null);
        }

        return StaffScheduleResource::collection($this->staffScheduleRepository->currentForStaff($request->staff_id))
            ->additional(['message' => 'Schedule change approved.', 'pending_request' => null]);
    }

    public function reject(User $user, string $uuid, ?string $reason)
    {
        if ($user->role !== 'business_owner') {
            return response()->json(['message' => 'Only the owner can reject a schedule change.'], 403);
        }

        $request = $this->findPending($user, $uuid);
        if (! $request) {
            return response()->json(['message' => 'This request has already been decided or withdrawn.'], 422);
        }

        $request->update([
            'status' => StaffScheduleChangeRequest::REJECTED,
            'decided_by_user_id' => $user->id,
            'decided_at' => now(),
            'decision_note' => $reason ?: null,
        ]);

        if ($request->requester) {
            $this->notificationService->scheduleChangeDecided($request->requester, $this->nameOf($request->staff), false, $reason);
        }

        return response()->json(['message' => 'Schedule change rejected.', 'pending_request' => null]);
    }

    // The manager who asked takes their own request back.
    public function withdraw(User $user, string $uuid)
    {
        $request = $this->findPending($user, $uuid);
        if (! $request) {
            return response()->json(['message' => 'This request has already been decided or withdrawn.'], 422);
        }

        if ($user->role !== 'business_owner' && $request->requested_by_user_id !== $user->id) {
            return response()->json(['message' => 'Only the person who made this request can withdraw it.'], 403);
        }

        $request->update(['status' => StaffScheduleChangeRequest::WITHDRAWN]);

        return response()->json(['message' => 'Request withdrawn.', 'pending_request' => null]);
    }

    // ── internals ───────────────────────────────────────────────────────

    private function needsApproval(SpaBusiness $business, User $user): bool
    {
        if ($user->role !== 'manager') {
            return false;
        }

        $policy = $business->settings?->section('staff_policy') ?? [];

        return (bool) ($policy['require_manager_approval'] ?? false);
    }

    private function pendingFor(int $staffId): ?StaffScheduleChangeRequest
    {
        return StaffScheduleChangeRequest::with(['staff', 'branch', 'requester.staff'])
            ->where('staff_id', $staffId)
            ->where('status', StaffScheduleChangeRequest::PENDING)
            ->latest()
            ->first();
    }

    private function findPending(User $user, string $uuid): ?StaffScheduleChangeRequest
    {
        return StaffScheduleChangeRequest::with(['staff', 'branch', 'requester.staff'])
            ->where('uuid', $uuid)
            ->whereIn('spa_branch_id', $this->branchIds($user))
            ->where('status', StaffScheduleChangeRequest::PENDING)
            ->first();
    }

    private function closePending(int $staffId, string $status): void
    {
        StaffScheduleChangeRequest::where('staff_id', $staffId)
            ->where('status', StaffScheduleChangeRequest::PENDING)
            ->update(['status' => $status, 'updated_at' => now()]);
    }

    private function present(?StaffScheduleChangeRequest $request): ?array
    {
        if (! $request) {
            return null;
        }

        return [
            'uuid' => $request->uuid,
            'status' => $request->status,
            'staff_uuid' => $request->staff?->uuid,
            'staff_name' => $request->staff ? $this->nameOf($request->staff) : null,
            'branch_name' => $request->branch?->branch_name,
            'requested_by' => $request->requester ? $this->userName($request->requester) : null,
            'requested_by_me' => $request->requested_by_user_id === request()->user()?->id,
            'requested_at' => $request->created_at?->toIso8601String(),
            // Same shape as StaffScheduleResource rows.
            'days' => array_values($request->days ?? []),
        ];
    }

    private function nameOf(?Staff $staff): string
    {
        return trim(($staff?->first_name ?? '') . ' ' . ($staff?->last_name ?? '')) ?: 'this staff member';
    }

    private function userName(User $user): string
    {
        $staffName = trim(($user->staff?->first_name ?? '') . ' ' . ($user->staff?->last_name ?? ''));

        return $staffName ?: (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'A manager');
    }
}

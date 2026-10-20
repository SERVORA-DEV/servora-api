<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\SpaBranch;
use App\Models\Staff;
use App\Models\StaffScheduleChangeRequest;
use App\Models\StaffTransferRequest;
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Repository\Business\StaffRepository;
use App\Repository\SpaBusinessRepository;
use App\Service\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

// Moving a staff member to another branch.
//
//   - The OWNER's move applies at once.
//   - A MANAGER's move is a request the owner accepts or declines (no switch
//     for it: a manager never moves anyone on their own). A manager can only
//     ask to move their own branch's staff out; they cannot move themselves
//     or another manager.
//   - Whoever is moved must have nothing still open at the old branch: a
//     booking with them assigned (or asked for by name) would otherwise be
//     left pointing at someone who works elsewhere. That is checked when the
//     move is asked for and again when it is applied.
//
// A staff member's login follows them (a login's branch is its staff row's
// branch), so a manager or front desk account lands in the new branch's
// scope. Their past attendance stays at the branch where it happened
// (attendances.spa_branch_id, App\Support\History).
class StaffTransferService
{
    // Bookings that are still to happen (or happening).
    private const OPEN_BOOKINGS = [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CHECKED_IN, Appointment::STATUS_IN_SERVICE];

    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private StaffRepository $staffRepository,
        private NotificationService $notificationService,
        private ManagerChangeNotifier $managerChanges,
    ) {
    }

    // ── Asking to move someone ──────────────────────────────────────────

    /**
     * Whether saving $toUuid on this staff member is a move, and whether this
     * account may ask for it. Null: not a move (no branch sent, or the same
     * one). A JSON refusal, or the branch they would move to.
     */
    public function check(User $user, Staff $staff, ?string $toUuid): JsonResponse|SpaBranch|null
    {
        if (! $toUuid) {
            return null;
        }
        $to = SpaBranch::where('uuid', $toUuid)->first();
        if ($to && $to->id === $staff->spa_branch_id) {
            return null;
        }

        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $to || ! $business || $to->spa_business_id !== $business->id) {
            return response()->json(['message' => 'That branch was not found.'], 404);
        }

        if ($user->role === 'manager') {
            if (! \App\Models\AccountBranch::ready() && $staff->user_id === $user->id) {
                return response()->json(['message' => "You can't move yourself to another branch. Ask the spa owner."], 422);
            }
            if ($staff->role === 'manager') {
                return response()->json(['message' => 'Only the spa owner can move a manager to another branch.'], 422);
            }
        }
        // (Before logins were given branches of their own, a login moved with its employee.)
        if (! \App\Models\AccountBranch::ready() && $staff->user_id !== null && $to->plan_hold_at) {
            return response()->json(['message' => "{$to->branch_name} is on hold, so someone with a login can't move there."], 422);
        }
        if ($problem = $this->openBookingsProblem($staff)) {
            return response()->json(['message' => $problem], 422);
        }

        return $to;
    }

    /**
     * Carries the move out once check() has passed: the owner's applies at
     * once; a manager's becomes a request.
     *
     * @return array{pending: bool, message: ?string, request: ?array}
     */
    public function submit(User $user, Staff $staff, SpaBranch $to): array
    {
        if ($user->role === 'business_owner') {
            $this->apply($staff, $to, $user);

            return ['pending' => false, 'message' => null, 'request' => null];
        }

        $from = $staff->branch;
        $request = DB::transaction(function () use ($staff, $user, $from, $to) {
            // One open request per person: a newer one replaces it.
            $this->closePending($staff->id, StaffTransferRequest::SUPERSEDED);

            return StaffTransferRequest::create([
                'staff_id' => $staff->id,
                'spa_business_id' => $to->spa_business_id,
                'from_branch_id' => $from?->id,
                'to_branch_id' => $to->id,
                'from_branch_name' => $from?->branch_name,
                'to_branch_name' => $to->branch_name,
                'requested_by_user_id' => $user->id,
                'status' => StaffTransferRequest::PENDING,
            ]);
        });

        $owner = $this->spaBusinessRepository->findForUser($user)?->owner;
        if ($owner) {
            $this->notificationService->staffTransferRequested(
                $owner, $this->nameOf($staff), $this->userName($user), $from?->branch_name, $to->branch_name, $staff->uuid,
            );
        }

        return [
            'pending' => true,
            'message' => "Sent to the owner. {$this->nameOf($staff)} moves to {$to->branch_name} once the owner confirms.",
            'request' => $this->present($request->load(['staff', 'requester.staff'])),
        ];
    }

    // ── The owner's decision ────────────────────────────────────────────

    public function pendingRequests(User $user)
    {
        $requests = StaffTransferRequest::with(['staff', 'requester.staff'])
            ->where('status', StaffTransferRequest::PENDING)
            ->whereIn('from_branch_id', $this->branchIds($user))
            ->latest()
            ->get();

        return response()->json(['data' => $requests->map(fn ($request) => $this->present($request))->values()]);
    }

    public function approve(User $user, string $uuid)
    {
        if ($user->role !== 'business_owner') {
            return response()->json(['message' => 'Only the owner can confirm a move to another branch.'], 403);
        }
        $request = $this->findPending($user, $uuid);
        if (! $request) {
            return response()->json(['message' => 'This request has already been decided or withdrawn.'], 422);
        }

        $staff = $request->staff;
        $to = $request->toBranch;
        if (! $staff || ! $to) {
            return response()->json(['message' => 'That staff member or branch no longer exists.'], 422);
        }
        // Moved by someone else since the request was made: nothing to do.
        if ($staff->spa_branch_id !== $request->from_branch_id) {
            $request->update(['status' => StaffTransferRequest::SUPERSEDED]);

            return response()->json(['message' => "{$this->nameOf($staff)} is no longer at {$request->from_branch_name}, so this request is closed."], 422);
        }
        if (! \App\Models\AccountBranch::ready() && $staff->user_id !== null && $to->plan_hold_at) {
            return response()->json(['message' => "{$to->branch_name} is on hold, so someone with a login can't move there."], 422);
        }
        if ($problem = $this->openBookingsProblem($staff)) {
            return response()->json(['message' => $problem], 422);
        }

        $this->apply($staff, $to, $user, $request);
        $request->update(['status' => StaffTransferRequest::APPROVED, 'decided_by_user_id' => $user->id, 'decided_at' => now()]);

        if ($request->requester) {
            $this->notificationService->staffTransferDecided(
                $request->requester, $this->nameOf($staff), $to->branch_name, true, null, $staff->uuid,
            );
        }

        return response()->json(['message' => "{$this->nameOf($staff)} moved to {$to->branch_name}.", 'request' => null]);
    }

    public function decline(User $user, string $uuid, ?string $reason)
    {
        if ($user->role !== 'business_owner') {
            return response()->json(['message' => 'Only the owner can decline a move to another branch.'], 403);
        }
        $request = $this->findPending($user, $uuid);
        if (! $request) {
            return response()->json(['message' => 'This request has already been decided or withdrawn.'], 422);
        }

        $request->update([
            'status' => StaffTransferRequest::DECLINED,
            'decided_by_user_id' => $user->id,
            'decided_at' => now(),
            'decision_note' => $reason ?: null,
        ]);
        if ($request->requester && $request->staff) {
            $this->notificationService->staffTransferDecided(
                $request->requester, $this->nameOf($request->staff), (string) $request->to_branch_name, false, $reason, $request->staff->uuid,
            );
        }

        return response()->json(['message' => 'Move declined.', 'request' => null]);
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

        $request->update(['status' => StaffTransferRequest::WITHDRAWN]);

        return response()->json(['message' => 'Request withdrawn.', 'request' => null]);
    }

    /** Every branch of the business: where a staff member could be moved to. */
    public function branchOptions(User $user)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        $branches = $business ? SpaBranch::where('spa_business_id', $business->id)->orderBy('branch_name')->get() : collect();

        return response()->json(['data' => $branches->map(fn (SpaBranch $b) => [
            'uuid' => $b->uuid,
            'name' => $b->branch_name,
            'on_hold' => (bool) $b->plan_hold_at,
        ])->values()]);
    }

    /** The pending request for one person, shaped for the page; null when none. */
    public function pendingFor(User $user, Staff $staff): ?array
    {
        if (! StaffTransferRequest::ready()) {
            return null;
        }

        return $this->present(
            StaffTransferRequest::with(['staff', 'requester.staff'])
                ->where('staff_id', $staff->id)
                ->where('status', StaffTransferRequest::PENDING)
                ->latest()
                ->first()
        );
    }

    // ── internals ───────────────────────────────────────────────────────

    // The move itself, in one go: the branch, a new employee number if theirs
    // is taken at the destination (they are unique per branch), and whatever
    // was waiting against the old branch closed.
    private function apply(Staff $staff, SpaBranch $to, User $actor, ?StaffTransferRequest $deciding = null): void
    {
        $from = $staff->branch;

        DB::transaction(function () use ($staff, $to, $deciding) {
            $changes = ['spa_branch_id' => $to->id];
            if ($staff->employee_number !== null
                && Staff::withTrashed()->where('spa_branch_id', $to->id)->where('employee_number', $staff->employee_number)->where('id', '!=', $staff->id)->exists()) {
                $changes['employee_number'] = $this->staffRepository->nextEmployeeNumber($to->id);
            }
            $staff->update($changes);

            // Written against the old branch.
            StaffScheduleChangeRequest::where('staff_id', $staff->id)
                ->where('status', StaffScheduleChangeRequest::PENDING)
                ->update(['status' => StaffScheduleChangeRequest::SUPERSEDED, 'updated_at' => now()]);
            StaffTransferRequest::where('staff_id', $staff->id)
                ->where('status', StaffTransferRequest::PENDING)
                ->when($deciding, fn ($q) => $q->where('id', '!=', $deciding->id))
                ->update(['status' => StaffTransferRequest::SUPERSEDED, 'updated_at' => now()]);
        });
        $staff->unsetRelation('branch');

        if (! $from) {
            return;
        }
        $name = $this->nameOf($staff);
        // The managers of both branches hear of it, by their own switches.
        $this->managerChanges->tell(
            $actor, $from, ManagerChangeNotifier::STAFF, 'Employee Moved',
            "moved {$name} from {$from->branch_name} to {$to->branch_name}.",
        );
        $this->managerChanges->tell(
            $actor, $to, ManagerChangeNotifier::STAFF, 'Employee Moved',
            "moved {$name} to {$to->branch_name} from {$from->branch_name}.",
            reference: $staff->uuid,
        );
    }

    // What is still open for them at the branch they are leaving, or null.
    private function openBookingsProblem(Staff $staff): ?string
    {
        $assigned = TherapistAssignment::where('staff_id', $staff->id)
            ->whereIn('assignment_status', ['Assigned', 'In Progress'])
            ->whereHas('appointmentService.appointment', fn ($q) => $q->whereIn('status', self::OPEN_BOOKINGS))
            ->count();
        $asked = Appointment::remembersRequests()
            ? Appointment::where('requested_staff_id', $staff->id)->whereIn('status', self::OPEN_BOOKINGS)->count()
            : 0;
        $open = $assigned + $asked;
        if ($open === 0) {
            return null;
        }

        $where = $staff->branch?->branch_name ?? 'their branch';

        return "{$this->nameOf($staff)} still has {$open} " . ($open === 1 ? 'booking' : 'bookings')
            . " open at {$where}. Finish or reassign " . ($open === 1 ? 'it' : 'them') . ' before moving them to another branch.';
    }

    private function findPending(User $user, string $uuid): ?StaffTransferRequest
    {
        return StaffTransferRequest::with(['staff.branch', 'toBranch', 'requester.staff'])
            ->where('uuid', $uuid)
            ->where('status', StaffTransferRequest::PENDING)
            ->whereIn('from_branch_id', $this->branchIds($user))
            ->first();
    }

    private function closePending(int $staffId, string $status): void
    {
        StaffTransferRequest::where('staff_id', $staffId)
            ->where('status', StaffTransferRequest::PENDING)
            ->update(['status' => $status, 'updated_at' => now()]);
    }

    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchIdsForUser($user);
    }

    private function present(?StaffTransferRequest $request): ?array
    {
        if (! $request) {
            return null;
        }

        return [
            'uuid' => $request->uuid,
            'status' => $request->status,
            'staff_uuid' => $request->staff?->uuid,
            'staff_name' => $request->staff ? $this->nameOf($request->staff) : null,
            // The names as they were asked (History::own style), so it reads
            // right whatever happens to the branches afterwards.
            'from_branch_name' => $request->from_branch_name,
            'to_branch_name' => $request->to_branch_name,
            'to_branch_uuid' => $request->toBranch?->uuid,
            'requested_by' => $request->requester ? $this->userName($request->requester) : null,
            'requested_by_me' => $request->requested_by_user_id === request()->user()?->id,
            'requested_at' => $request->created_at?->toIso8601String(),
        ];
    }

    private function nameOf(?Staff $staff): string
    {
        return trim(($staff?->first_name ?? '') . ' ' . ($staff?->last_name ?? '')) ?: 'this staff member';
    }

    private function userName(User $user): string
    {
        return $user->displayName();
    }
}

<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\BranchClosure;
use App\Models\SpaBranch;
use App\Models\User;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Support\BranchClosures;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// Temporary closures of a branch (Branch Settings → Opening Hours): a
// holiday, repairs, a private event. The owner manages any of their
// branches, a manager their own. What a closure blocks is BranchClosures'
// job; this is the screen's list, create, edit and remove.
//
// Bookings already inside a closure are never cancelled here — they are
// counted and listed so staff can move or cancel each one.
class BranchClosureService
{
    // How many of the bookings inside a closure are listed by name.
    private const LISTED_BOOKINGS = 20;

    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private SpaBranchRepository $spaBranchRepository,
        private AppointmentAvailabilityService $availabilityService,
        private StaffNotifier $staffNotifier,
    ) {
    }

    public function list(User $user, string $branchUuid)
    {
        $branch = $this->branchOf($user, $branchUuid);

        return response()->json(['data' => BranchClosures::upcoming($branch->id)->map(fn (BranchClosure $c) => $this->present($branch, $c))->values()]);
    }

    // The bookings a closure for this window would land on — shown before
    // saving, so nobody closes over a full afternoon by accident.
    public function conflicts(User $user, string $branchUuid, array $payload)
    {
        $branch = $this->branchOf($user, $branchUuid);
        [$start, $end] = $this->window($payload);

        return response()->json(['data' => $this->bookingsInside($branch, $start, $end)]);
    }

    public function create(User $user, string $branchUuid, array $payload)
    {
        $branch = $this->branchOf($user, $branchUuid);
        [$start, $end] = $this->window($payload);

        if ($problem = $this->problem($branch, $start, $end)) {
            return response()->json(['message' => $problem], 422);
        }

        $closure = BranchClosure::create([
            'spa_branch_id' => $branch->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'all_day' => (bool) ($payload['all_day'] ?? false),
            'reason' => $payload['reason'],
            'note' => trim($payload['note'] ?? '') ?: null,
            'created_by_user_id' => $user->id,
        ]);

        $this->announce($user, $branch, 'Branch Closing', "closed {$branch->branch_name}: " . BranchClosures::period($closure) . " ({$closure->reason}).");
        // The clients booked inside it are told, so nobody turns up to a closed door.
        app(\App\Service\Business\ClientBookingNotices::class)->closing($branch, $start, $end, BranchClosures::period($closure), $closure->reason);

        return response()->json(['message' => 'Closure saved.', 'data' => $this->present($branch, $closure)], 201);
    }

    public function update(User $user, string $branchUuid, string $closureUuid, array $payload)
    {
        $branch = $this->branchOf($user, $branchUuid);
        $closure = $this->closureOf($branch, $closureUuid);
        [$start, $end] = $this->window($payload);

        if ($problem = $this->problem($branch, $start, $end, $closure->id)) {
            return response()->json(['message' => $problem], 422);
        }

        $closure->update([
            'starts_at' => $start,
            'ends_at' => $end,
            'all_day' => (bool) ($payload['all_day'] ?? false),
            'reason' => $payload['reason'],
            'note' => trim($payload['note'] ?? '') ?: null,
        ]);

        $this->announce($user, $branch, 'Branch Closure Changed', "changed a closure of {$branch->branch_name}: now " . BranchClosures::period($closure) . " ({$closure->reason}).");
        app(\App\Service\Business\ClientBookingNotices::class)->closing($branch, $start, $end, BranchClosures::period($closure), $closure->reason);

        return response()->json(['message' => 'Closure saved.', 'data' => $this->present($branch, $closure)]);
    }

    // One that hasn't started is removed. One already running is ended now
    // instead, so the record of the branch having been closed is kept.
    public function remove(User $user, string $branchUuid, string $closureUuid)
    {
        $branch = $this->branchOf($user, $branchUuid);
        $closure = $this->closureOf($branch, $closureUuid);

        if (BranchClosures::isActive($closure)) {
            $window = [$closure->starts_at->copy(), $closure->ends_at->copy()];
            $closure->update(['ends_at' => Carbon::now()]);
            $this->announce($user, $branch, 'Branch Reopened', "reopened {$branch->branch_name} early. It is taking bookings again.");
            // The clients whose bookings the rest of the closure had put in doubt.
            app(\App\Service\Business\ClientBookingNotices::class)->reopened($branch, Carbon::now(), $window[1]);

            return response()->json(['message' => 'The branch is open again.']);
        }

        $period = BranchClosures::period($closure);
        $window = [$closure->starts_at->copy(), $closure->ends_at->copy()];
        $closure->delete();
        app(\App\Service\Business\ClientBookingNotices::class)->reopened($branch, $window[0], $window[1]);
        $this->announce($user, $branch, 'Branch Closure Removed', "removed the closure of {$branch->branch_name} planned for {$period}.");

        return response()->json(['message' => 'Closure removed.']);
    }

    // ── internals ───────────────────────────────────────────────────────

    // 404s unless the branch is one of the caller's own (an owner's
    // branches, or a manager's one branch).
    private function branchOf(User $user, string $uuid): SpaBranch
    {
        abort_unless($this->spaBusinessRepository->findForUser($user), 422, 'No spa business found for this account.');
        abort_unless(BranchClosures::ready(), 503, 'Closures are not set up on this server yet.');

        return $this->spaBranchRepository->findByUuidForBranches($uuid, $this->spaBusinessRepository->branchIdsForUser($user));
    }

    private function closureOf(SpaBranch $branch, string $uuid): BranchClosure
    {
        return BranchClosure::where('spa_branch_id', $branch->id)
            ->where('uuid', $uuid)
            ->where('ends_at', '>', Carbon::now())
            ->firstOrFail();
    }

    // [start, end) from the request. All day: from the start of the first
    // day to the start of the day after the last.
    private function window(array $payload): array
    {
        $start = Carbon::parse($payload['starts_at']);
        $end = Carbon::parse($payload['ends_at']);

        if ($payload['all_day'] ?? false) {
            return [$start->startOfDay(), $end->startOfDay()->addDay()];
        }

        return [$start, $end];
    }

    private function problem(SpaBranch $branch, Carbon $start, Carbon $end, ?int $exceptId = null): ?string
    {
        if ($end->lte($start)) {
            return 'The closure must end after it starts.';
        }
        if ($end->lte(Carbon::now())) {
            return 'That time has already passed. Pick an end that is still to come.';
        }
        if ($start->diffInDays($end) > BranchClosures::MAX_DAYS) {
            return 'A closure can be up to ' . BranchClosures::MAX_DAYS . ' days. To close for longer, deactivate the branch instead.';
        }

        $other = BranchClosures::overlapping($branch->id, $start, $end)->first(fn (BranchClosure $c) => $c->id !== $exceptId);
        if ($other) {
            return 'This overlaps another closure (' . BranchClosures::period($other) . '). Change that one instead.';
        }

        return null;
    }

    // Appointments still to happen that start inside, or run into, the window.
    private function bookingsInside(SpaBranch $branch, Carbon $start, Carbon $end): array
    {
        $inside = Appointment::where('spa_branch_id', $branch->id)
            ->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CHECKED_IN, Appointment::STATUS_IN_SERVICE])
            // A day early, for a visit that started the evening before.
            ->whereBetween('appointment_date', [$start->copy()->subDay()->toDateString(), $end->toDateString()])
            ->with(['client:id,first_name,last_name', 'services.serviceVariant'])
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->get()
            ->filter(function (Appointment $a) use ($start, $end) {
                $from = Carbon::parse(Carbon::parse($a->appointment_date)->toDateString() . ' ' . substr((string) $a->appointment_time, 0, 5));
                $until = $from->copy()->addMinutes(max($this->availabilityService->estimatedDurationMinutes($a), 30));

                return $from->lt($end) && $until->gt($start);
            })
            ->values();

        return [
            'count' => $inside->count(),
            'appointments' => $this->listed($inside),
        ];
    }

    private function listed(Collection $appointments): array
    {
        return $appointments->take(self::LISTED_BOOKINGS)->map(fn (Appointment $a) => [
            'uuid' => $a->uuid,
            'number' => $a->appointment_number,
            'client' => trim(($a->client?->first_name ?? '') . ' ' . ($a->client?->last_name ?? '')) ?: 'A client',
            'date' => Carbon::parse($a->appointment_date)->toDateString(),
            'time' => substr((string) $a->appointment_time, 0, 5),
            'status' => $a->status,
        ])->all();
    }

    private function present(SpaBranch $branch, BranchClosure $closure): array
    {
        return BranchClosures::present($closure) + [
            'bookings_inside' => $this->bookingsInside($branch, $closure->starts_at, $closure->ends_at)['count'],
        ];
    }

    // Everyone who runs the branch, and the owner — except whoever did it.
    private function announce(User $actor, SpaBranch $branch, string $title, string $what): void
    {
        $name = $actor->displayName();

        $this->staffNotifier->branch($branch, $title, "{$name} {$what}", exceptUserId: $actor->id, reference: $branch->uuid);
    }
}

<?php

namespace App\Service\Business;

use App\Http\Resources\CustomerProgramResource;
use App\Models\CustomerProgram;
use App\Models\ProgramRequest;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Service\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

// Customer programs are the owner's. A manager who wants a new voucher,
// discount or membership for their branch fills it in here and sends it; the
// owner approves it (as asked, or changed first) or declines, and the manager
// is told either way. An approved program is created by the owner's own save
// (CustomerProgramService::store) and offered at the branch that asked, and
// only there until the owner offers it somewhere else.
//
// The same shape as CatalogRequestService (a manager asking for a service).
class ProgramRequestService
{
    // How many requests one branch may have waiting at a time.
    private const MAX_PENDING = 10;

    // How long an answered request stays on the manager's list.
    private const KEEP_DECIDED_DAYS = 30;

    // The parts of a program a request carries, as the owner's save takes them.
    private const PARTS = ['values', 'condition', 'audience', 'includes'];

    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private NotificationService $notificationService,
        private CustomerProgramService $programs,
        private ProgramRewardService $rewards,
    ) {
    }

    // The owner sees every request still waiting, across the business. A
    // manager sees their own branch's: the waiting ones, and the answered
    // ones for a while so the answer can be read. `available` is false while
    // the feature can't be used (not migrated yet, or programs are off).
    public function list(User $user)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business || ! ProgramRequest::ready() || ! $this->rewards->enabledFor($business->id)) {
            return response()->json(['data' => [], 'available' => false]);
        }

        $query = ProgramRequest::with(['branch', 'requester.staff', 'program'])
            ->where('spa_business_id', $business->id);

        if ($user->role === 'business_owner') {
            $query->where('status', ProgramRequest::PENDING);
        } else {
            $query->whereIn('spa_branch_id', $this->spaBusinessRepository->branchIdsForUser($user))
                ->where(function ($q) {
                    $q->where('status', ProgramRequest::PENDING)
                        ->orWhere(function ($decided) {
                            $decided->whereIn('status', [ProgramRequest::APPROVED, ProgramRequest::DECLINED])
                                ->where('decided_at', '>=', now()->subDays(self::KEEP_DECIDED_DAYS));
                        });
                });
        }

        $requests = $query->latest()->limit(50)->get();

        return response()->json([
            'data' => $requests->map(fn ($request) => $this->present($request, $user))->values(),
            'available' => true,
        ]);
    }

    public function create(User $user, array $payload)
    {
        if ($user->role !== 'manager') {
            return $this->refuse('Only a branch manager sends a request. The owner creates programs directly.', 403);
        }

        $business = $this->spaBusinessRepository->findForUser($user);
        // The branch they are working in (App\Support\AccountScope::workingBranch).
        $branch = \App\Support\AccountScope::workingBranch($user);
        if (! $business || ! $branch) {
            return $this->refuse('No branch found for this account.');
        }
        if (! ProgramRequest::ready()) {
            return $this->refuse('Requests for new programs aren\'t available yet.');
        }
        if (! $this->rewards->enabledFor($business->id)) {
            return $this->refuse('Customer programs are turned off for this business.');
        }

        $type = $payload['type'];
        $name = trim(preg_replace('/\s+/', ' ', $payload['name']));
        $lower = mb_strtolower($name);

        // Held to the rules the owner's own save is held to, so the owner
        // never gets a request that couldn't be created.
        $parts = Arr::only($payload, self::PARTS);
        if ($problem = $this->programs->check($type, $parts, $business)) {
            return $this->refuse($problem);
        }

        if (CustomerProgram::where('spa_business_id', $business->id)->where('type', $type)->whereRaw('LOWER(name) = ?', [$lower])->exists()) {
            return $this->refuse("A {$type} called \"{$name}\" already exists. Use another name.");
        }

        $waiting = ProgramRequest::where('spa_branch_id', $branch->id)->where('status', ProgramRequest::PENDING);

        if ((clone $waiting)->where('type', $type)->whereRaw('LOWER(name) = ?', [$lower])->exists()) {
            return $this->refuse("\"{$name}\" has already been requested and is waiting for the owner.");
        }

        if ($waiting->count() >= self::MAX_PENDING) {
            return $this->refuse('Your branch already has ' . self::MAX_PENDING . ' requests waiting. Wait for the owner to answer some first.');
        }

        if (array_key_exists('values', $parts)) {
            // The readable parts ("a Massage service") are written now, so
            // the owner's list reads the same as the finished program will.
            $parts['values'] = $this->programs->normalise($type, $parts['values'], $business);
        }

        $request = ProgramRequest::create([
            'spa_business_id' => $business->id,
            'spa_branch_id' => $branch->id,
            'requested_by_user_id' => $user->id,
            'type' => $type,
            'name' => $name,
            'payload' => $parts,
            'note' => trim($payload['note'] ?? '') ?: null,
            'status' => ProgramRequest::PENDING,
        ]);

        if ($business->owner) {
            $this->notificationService->programRequested($business->owner, $type, $name, $this->userName($user), $branch->branch_name);
        }

        return response()->json([
            'message' => 'Sent to the owner.',
            'data' => $this->present($request->load(['branch', 'requester.staff']), $user),
        ], 201);
    }

    // The manager who asked takes their own request back.
    public function withdraw(User $user, string $uuid)
    {
        $request = $this->findPending($user, $uuid);
        if (! $request) {
            return $this->refuse('This request has already been answered or withdrawn.');
        }

        if ($user->role !== 'business_owner' && $request->requested_by_user_id !== $user->id) {
            return $this->refuse('Only the person who made this request can withdraw it.', 403);
        }

        $request->update(['status' => ProgramRequest::WITHDRAWN]);

        return response()->json(['message' => 'Request withdrawn.']);
    }

    // The owner says yes. $changed is the owner's own version of the program
    // (name and parts) when they changed it first; null creates it as asked.
    public function approve(User $user, string $uuid, ?array $changed = null)
    {
        if ($user->role !== 'business_owner') {
            return $this->refuse('Only the owner can answer a request.', 403);
        }

        return DB::transaction(function () use ($user, $uuid, $changed) {
            $request = $this->findPending($user, $uuid, lock: true);
            if (! $request) {
                return $this->refuse('This request has already been answered or withdrawn.');
            }
            if (! $request->branch) {
                return $this->refuse('The branch that asked for this no longer exists. Decline the request instead.');
            }

            $name = trim((string) ($changed['name'] ?? '')) ?: $request->name;
            $parts = $changed !== null && array_key_exists('values', $changed)
                ? Arr::only($changed, self::PARTS)
                : ($request->payload ?? []);

            $created = $this->programs->store($user, ['type' => $request->type, 'name' => $name] + $parts, onlyFor: $request->branch);
            if ($created instanceof JsonResponse) {
                // Why it can't be created (a service it was for has gone,
                // say): the request stays waiting for "change first".
                return $created;
            }

            $request->update([
                'status' => ProgramRequest::APPROVED,
                'decided_by_user_id' => $user->id,
                'decided_at' => now(),
                'customer_program_id' => $created->resource->id,
            ]);

            if ($request->requester) {
                $this->notificationService->programRequestDecided($request->requester, $request->type, $created->resource->name, true, null, $request->branch->branch_name);
            }

            return response()->json([
                'message' => 'Approved. It is now offered at ' . $request->branch->branch_name . '.',
                'data' => $this->present($request->load('program'), $user),
                'program' => $created->resolve(),
            ]);
        });
    }

    public function decline(User $user, string $uuid, ?string $reason)
    {
        if ($user->role !== 'business_owner') {
            return $this->refuse('Only the owner can answer a request.', 403);
        }

        $request = $this->findPending($user, $uuid);
        if (! $request) {
            return $this->refuse('This request has already been answered or withdrawn.');
        }

        $reason = trim($reason ?? '') ?: null;

        $request->update([
            'status' => ProgramRequest::DECLINED,
            'decided_by_user_id' => $user->id,
            'decided_at' => now(),
            'decision_note' => $reason,
        ]);

        if ($request->requester) {
            $this->notificationService->programRequestDecided($request->requester, $request->type, $request->name, false, $reason, $request->branch?->branch_name);
        }

        return response()->json(['message' => 'Request declined.', 'data' => $this->present($request, $user)]);
    }

    // ── internals ───────────────────────────────────────────────────────

    // Scoped to the caller's branches: an owner reaches every branch of their
    // business, a manager only their own.
    private function findPending(User $user, string $uuid, bool $lock = false): ?ProgramRequest
    {
        if (! ProgramRequest::ready()) {
            return null;
        }

        return ProgramRequest::with(['branch', 'requester.staff'])
            ->where('uuid', $uuid)
            ->whereIn('spa_branch_id', $this->spaBusinessRepository->branchIdsForUser($user))
            ->where('status', ProgramRequest::PENDING)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    // `program` is the asked-for program in the shape CustomerProgramResource
    // gives a real one, so the site draws both with the same code.
    private function present(ProgramRequest $request, User $viewer): array
    {
        $parts = $request->payload ?? [];

        return [
            'uuid' => $request->uuid,
            'type' => $request->type,
            'name' => $request->name,
            'note' => $request->note,
            'status' => $request->status,
            'program' => [
                'uuid' => $request->uuid,
                'type' => $request->type,
                'name' => $request->name,
                'active' => true,
                'audience' => $parts['audience'] ?? 'all',
                'values' => (object) ($parts['values'] ?? []),
                'condition' => $request->type === 'voucher' ? [
                    'kind' => $parts['condition']['kind'] ?? null,
                    'value' => $parts['condition']['value'] ?? null,
                ] : null,
                'includes' => $request->type === 'membership' ? [
                    'voucher_uuids' => array_values($parts['includes']['voucher_uuids'] ?? []),
                    'discount_uuids' => array_values($parts['includes']['discount_uuids'] ?? []),
                ] : null,
                'branch_uuids' => array_values(array_filter([$request->branch?->uuid])),
            ],
            // The program an approval created (it may have been renamed).
            'program_uuid' => $request->program?->uuid,
            'program_name' => $request->program?->name,
            'branch_uuid' => $request->branch?->uuid,
            'branch_name' => $request->branch?->branch_name,
            'requested_by' => $request->requester ? $this->userName($request->requester) : null,
            'requested_by_me' => $request->requested_by_user_id === $viewer->id,
            'requested_at' => $request->created_at?->toIso8601String(),
            'decided_at' => $request->decided_at?->toIso8601String(),
            'decision_note' => $request->decision_note,
        ];
    }

    private function userName(User $user): string
    {
        return $user->displayName();
    }

    private function refuse(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}

<?php

namespace App\Service\Business;

use App\Models\CatalogRequest;
use App\Models\Package;
use App\Models\Service;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Service\NotificationService;

// The catalog (services and packages) is the owner's, shared by every branch.
// A manager who needs something it doesn't have asks for it here; the owner
// adds it or declines, and the manager is told either way.
class CatalogRequestService
{
    // How many requests one branch may have waiting at a time.
    private const MAX_PENDING = 10;

    // How long an answered request stays on the manager's list.
    private const KEEP_DECIDED_DAYS = 30;

    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private NotificationService $notificationService,
    ) {
    }

    // The owner sees every request still waiting, across the business. A
    // manager sees their own branch's: the waiting ones, and the answered
    // ones for a while so the answer can be read.
    public function list(User $user)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['data' => []]);
        }

        $query = CatalogRequest::with(['branch', 'requester.staff'])
            ->where('spa_business_id', $business->id);

        if ($user->role === 'business_owner') {
            $query->where('status', CatalogRequest::PENDING);
        } else {
            $query->whereIn('spa_branch_id', $this->spaBusinessRepository->branchIdsForUser($user))
                ->where(function ($q) {
                    $q->where('status', CatalogRequest::PENDING)
                        ->orWhere(function ($decided) {
                            $decided->whereIn('status', [CatalogRequest::ADDED, CatalogRequest::DECLINED])
                                ->where('decided_at', '>=', now()->subDays(self::KEEP_DECIDED_DAYS));
                        });
                });
        }

        $requests = $query->latest()->limit(50)->get();

        return response()->json(['data' => $requests->map(fn ($request) => $this->present($request, $user))->values()]);
    }

    public function create(User $user, array $payload)
    {
        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Only a branch manager sends a request. The owner adds services directly.'], 403);
        }

        $business = $this->spaBusinessRepository->findForUser($user);
        $branch = $user->staff?->branch;
        if (! $business || ! $branch) {
            return response()->json(['message' => 'No branch found for this account.'], 422);
        }

        $kind = $payload['kind'];
        $name = trim(preg_replace('/\s+/', ' ', $payload['name']));
        $lower = mb_strtolower($name);

        $catalog = $kind === 'package' ? Package::query() : Service::query();
        if ($catalog->where('spa_business_id', $business->id)->whereRaw('LOWER(name) = ?', [$lower])->exists()) {
            return response()->json(['message' => "\"{$name}\" is already in the catalog. Switch it on for your branch instead."], 422);
        }

        $waiting = CatalogRequest::where('spa_branch_id', $branch->id)->where('status', CatalogRequest::PENDING);

        if ((clone $waiting)->where('kind', $kind)->whereRaw('LOWER(name) = ?', [$lower])->exists()) {
            return response()->json(['message' => "\"{$name}\" has already been requested and is waiting for the owner."], 422);
        }

        if ($waiting->count() >= self::MAX_PENDING) {
            return response()->json(['message' => 'Your branch already has ' . self::MAX_PENDING . ' requests waiting. Wait for the owner to answer some first.'], 422);
        }

        $request = CatalogRequest::create([
            'spa_business_id' => $business->id,
            'spa_branch_id' => $branch->id,
            'requested_by_user_id' => $user->id,
            'kind' => $kind,
            'name' => $name,
            // A package has no category.
            'category' => $kind === 'service' ? (trim($payload['category'] ?? '') ?: null) : null,
            'details' => trim($payload['details'] ?? '') ?: null,
            'status' => CatalogRequest::PENDING,
        ]);

        if ($business->owner) {
            $this->notificationService->catalogRequested($business->owner, $kind, $name, $this->userName($user), $branch->branch_name);
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
            return response()->json(['message' => 'This request has already been answered or withdrawn.'], 422);
        }

        if ($user->role !== 'business_owner' && $request->requested_by_user_id !== $user->id) {
            return response()->json(['message' => 'Only the person who made this request can withdraw it.'], 403);
        }

        $request->update(['status' => CatalogRequest::WITHDRAWN]);

        return response()->json(['message' => 'Request withdrawn.']);
    }

    // The owner has added it to the catalog (or it was there already).
    public function markAdded(User $user, string $uuid)
    {
        return $this->decide($user, $uuid, CatalogRequest::ADDED, null);
    }

    public function decline(User $user, string $uuid, ?string $reason)
    {
        return $this->decide($user, $uuid, CatalogRequest::DECLINED, $reason);
    }

    // ── internals ───────────────────────────────────────────────────────

    private function decide(User $user, string $uuid, string $status, ?string $reason)
    {
        if ($user->role !== 'business_owner') {
            return response()->json(['message' => 'Only the owner can answer a request.'], 403);
        }

        $request = $this->findPending($user, $uuid);
        if (! $request) {
            return response()->json(['message' => 'This request has already been answered or withdrawn.'], 422);
        }

        $reason = trim($reason ?? '') ?: null;

        $request->update([
            'status' => $status,
            'decided_by_user_id' => $user->id,
            'decided_at' => now(),
            'decision_note' => $reason,
        ]);

        if ($request->requester) {
            $this->notificationService->catalogRequestDecided(
                $request->requester,
                $request->kind,
                $request->name,
                $status === CatalogRequest::ADDED,
                $reason,
                $request->branch?->branch_name,
            );
        }

        return response()->json([
            'message' => $status === CatalogRequest::ADDED ? 'Marked as added.' : 'Request declined.',
            'data' => $this->present($request, $user),
        ]);
    }

    // Scoped to the caller's branches: an owner reaches every branch of their
    // business, a manager only their own.
    private function findPending(User $user, string $uuid): ?CatalogRequest
    {
        return CatalogRequest::with(['branch', 'requester.staff'])
            ->where('uuid', $uuid)
            ->whereIn('spa_branch_id', $this->spaBusinessRepository->branchIdsForUser($user))
            ->where('status', CatalogRequest::PENDING)
            ->first();
    }

    private function present(CatalogRequest $request, User $viewer): array
    {
        return [
            'uuid' => $request->uuid,
            'kind' => $request->kind,
            'name' => $request->name,
            'category' => $request->category,
            'details' => $request->details,
            'status' => $request->status,
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
        $staffName = trim(($user->staff?->first_name ?? '') . ' ' . ($user->staff?->last_name ?? ''));

        return $staffName ?: (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'A manager');
    }
}

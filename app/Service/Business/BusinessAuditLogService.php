<?php

namespace App\Service\Business;

use App\Http\Resources\BusinessAuditLogResource;
use App\Models\AccountBranch;
use App\Models\AuditLog;
use App\Models\SpaBranch;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Support\AccountScope;
use App\Support\BusinessActivityDescriber;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

// Business → Audit Log: who did what, where (rows written by
// RecordBusinessActivity and, for sign-ins, AuditLogRepository).
//
//   The owner sees everything in their business: every branch, the
//   business-wide changes and their own actions.
//   A manager sees what the logins did at the branches they run (Management
//   level) — front-desk work there included — and those logins' sign-ins;
//   never the owner's own actions.
class BusinessAuditLogService
{
    private const PER_PAGE = 50;

    public function __construct(private SpaBusinessRepository $businesses) {}

    public function list(User $user, array $filters)
    {
        $business = $this->businesses->findForUser($user);
        if (! $business || ! AuditLog::businessReady()) {
            return response()->json(['data' => [], 'meta' => ['total' => 0, 'current_page' => 1, 'last_page' => 1], 'people' => [], 'branches' => []]);
        }

        $isOwner = $user->role === 'business_owner';

        if ($isOwner) {
            $branches = SpaBranch::where('spa_business_id', $business->id)->orderBy('branch_name')->get(['id', 'uuid', 'branch_name']);
            $picked = ! empty($filters['branch']) ? $branches->firstWhere('uuid', $filters['branch']) : null;
            $branchIds = $picked ? [$picked->id] : null;
        } else {
            $run = AccountScope::branches($user, AccountBranch::MANAGEMENT, false);
            if ($run->isEmpty()) {
                return response()->json(['message' => 'The Audit Log is for the branches you manage.'], 403);
            }
            // The branch the screen is working on, when the web says which.
            $working = request()->header('X-Branch');
            if ($working && ! $run->contains('uuid', $working) && AccountScope::allBranches($user)->contains('uuid', $working)) {
                return response()->json(['message' => 'You work this branch at the front desk only, so its Audit Log is not yours to see.'], 403);
            }
            $branches = $run->sortBy('branch_name')->values();
            $wanted = $filters['branch'] ?? $working;
            $picked = $wanted ? $branches->firstWhere('uuid', $wanted) : null;
            $branchIds = $picked ? [$picked->id] : $branches->pluck('id')->all();
        }

        $scoped = AuditLog::query()
            ->where('spa_business_id', $business->id)
            ->whereNotNull('summary');

        if (! $isOwner) {
            $scoped->where(fn (Builder $q) => $q->whereNull('actor_role')->orWhere('actor_role', '!=', 'business_owner'));
        }
        if ($branchIds !== null) {
            $people = AccountScope::userIdsAt($branchIds, ['manager', 'frontdesk']);
            $scoped->where(function (Builder $q) use ($branchIds, $people) {
                $q->whereIn('spa_branch_id', $branchIds);
                if ($people) {
                    $q->orWhere(fn (Builder $s) => $s->where('area', 'sign_in')->whereIn('user_id', $people));
                }
            });
        }

        $peopleList = $this->people(clone $scoped);

        $query = clone $scoped;
        if (! empty($filters['area']) && in_array($filters['area'], BusinessActivityDescriber::AREAS, true)) {
            $query->where('area', $filters['area']);
        }
        if (! empty($filters['person'])) {
            $query->where('user_id', User::where('uuid', $filters['person'])->value('id') ?? 0);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'], config('app.timezone'))->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', Carbon::parse($filters['to'], config('app.timezone'))->addDay()->startOfDay());
        }
        if (! empty($filters['q'])) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])) . '%';
            $query->where(fn (Builder $q) => $q->where('summary', 'ilike', $like)
                ->orWhere('subject_label', 'ilike', $like)
                ->orWhere('actor_name', 'ilike', $like)
                ->orWhere('branch_name', 'ilike', $like));
        }

        $perPage = min(max((int) ($filters['per_page'] ?? self::PER_PAGE), 10), 200);
        $page = $query->with('user:id,uuid')->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
        $this->attachLinks($page->getCollection());

        return BusinessAuditLogResource::collection($page)->additional([
            'people' => $peopleList,
            'branches' => $branches->map(fn ($b) => ['id' => $b->uuid, 'name' => $b->branch_name])->values(),
        ]);
    }

    /** Everyone who appears in this log, for the person picker. */
    private function people(Builder $scoped): array
    {
        $rows = $scoped->reorder()
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->select('user_id', DB::raw('max(actor_name) as name'), DB::raw('max(actor_role) as role'))
            ->limit(300)
            ->get();
        $uuids = User::whereIn('id', $rows->pluck('user_id'))->pluck('uuid', 'id');

        return $rows->map(fn ($r) => ['id' => $uuids[$r->user_id] ?? null, 'name' => $r->name, 'role' => $r->role])
            ->filter(fn ($p) => $p['id'])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    // Where a row's record can be opened: a client's or an employee's page.
    private function attachLinks($logs): void
    {
        $tables = ['clients' => '/business/clients/', 'staff' => '/business/employees/'];
        foreach ($tables as $table => $path) {
            $ids = $logs->where('table_name', $table)->pluck('record_id')->filter()->unique()->values()->all();
            if (! $ids) {
                continue;
            }
            $uuids = DB::table($table)->whereIn('id', $ids)->whereNull('deleted_at')->pluck('uuid', 'id');
            foreach ($logs as $log) {
                if ($log->table_name === $table && isset($uuids[$log->record_id])) {
                    $log->setAttribute('link', $path . $uuids[$log->record_id]);
                }
            }
        }
    }
}

<?php

namespace App\Repository\Business;

use App\Enums\AppointmentStatus;
use App\Enums\VoucherStatus;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

class ClientRepository
{

    // The client's visits for the list: finished ones (how many, the last one, what they came
    // to) and the next one booked. A manager's count only their own branch's.
    private function doneVisits(?array $branchIds): \Closure
    {
        return fn ($q) => $q->where('status', AppointmentStatus::Completed->value)
            ->when($branchIds !== null, fn ($a) => $a->whereIn('spa_branch_id', $branchIds));
    }

    private function bookedVisits(?array $branchIds): \Closure
    {
        return fn ($q) => $q->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::CheckedIn->value, AppointmentStatus::InService->value])
            ->where('appointment_date', '>=', now()->toDateString())
            ->when($branchIds !== null, fn ($a) => $a->whereIn('spa_branch_id', $branchIds));
    }

    // SQL for where a client last came (a finished visit, in scope), else where they were added:
    // the branch the list shows them at.
    private function listBranchSql(?array $branchIds): string
    {
        $scope = $branchIds !== null ? ' and a.spa_branch_id in (' . implode(',', array_map('intval', $branchIds ?: [0])) . ')' : '';

        return '(select a.spa_branch_id from appointments a where a.client_id = clients.id and a.deleted_at is null'
            . " and a.status = '" . AppointmentStatus::Completed->value . "'$scope"
            . ' order by a.appointment_date desc, a.appointment_time desc limit 1)';
    }

    // The business's active clients (a manager: the ones of their branches), each with their
    // visit figures. Shared by the list and its figures.
    private function listQuery(int $spaBusinessId, ?array $branchIds)
    {
        $done = $this->doneVisits($branchIds);
        $booked = $this->bookedVisits($branchIds);

        return Client::query()
            ->withCount(['vouchers as vouchers_available_count' => fn ($q) => $q->where('status', VoucherStatus::Available->value)
                ->where(fn ($e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now())),
                // Missed bookings since they last came in — the no-show policy counts these.
                'appointments as no_show_count' => fn ($q) => $q->missedSinceLastVisit(),
                'appointments as visits_count' => $done])
            ->withMax(['appointments as last_visit_date' => $done], 'appointment_date')
            ->withSum(['appointments as visits_total' => $done], 'total_amount')
            ->withMin(['appointments as next_visit_date' => $booked], 'appointment_date')
            ->addSelect(['last_branch_id' => \App\Models\Appointment::select('spa_branch_id')
                ->whereColumn('client_id', 'clients.id')
                ->where('status', AppointmentStatus::Completed->value)
                ->when($branchIds !== null, fn ($a) => $a->whereIn('spa_branch_id', $branchIds))
                ->orderByDesc('appointment_date')->orderByDesc('appointment_time')
                ->limit(1)])
            ->where('spa_business_id', $spaBusinessId)
            ->where('is_active', true)
            ->when($branchIds !== null, fn ($q) => $this->scopeToBranches($q, $branchIds));
    }

    /**
     * Client search and the owner's client list: name, phone or email (`$query`), business-scoped.
     * Used by the desk's "search existing client" step and by the Clients page, which also asks
     * for (`$filters`):
     *   segment     new (joined this month), regular (3+ visits), upcoming (booked ahead),
     *               lapsed (last visit 60+ days ago, nothing booked), members, app
     *   branch_id   the branch the client is listed at (where they last came, else were added)
     *   sort        recent, visits, spent, newest, points, name (the desk: by first name)
     */
    public function search(int $spaBusinessId, ?string $query, int $perPage = 15, ?array $branchIds = null, array $filters = [])
    {
        $done = $this->doneVisits($branchIds);
        $booked = $this->bookedVisits($branchIds);
        $today = now()->toDateString();

        return $this->listQuery($spaBusinessId, $branchIds)
            ->with(['user', 'activeMembership.program'])
            ->when($query, function ($q) use ($query) {
                // whereLike, not where(..., 'like', ...): it defaults to
                // case-insensitive and compiles to ilike on PostgreSQL, where
                // a plain LIKE would make "maria" stop matching "Maria".
                $q->where(function ($inner) use ($query) {
                    $inner->whereLike('first_name', "%{$query}%")
                        ->orWhereLike('last_name', "%{$query}%")
                        ->orWhereLike('phone_number', "%{$query}%")
                        ->orWhereLike('email', "%{$query}%")
                        ->orWhereLike(DB::raw("concat(first_name, ' ', last_name)"), "%{$query}%");
                });
            })
            ->when($filters['segment'] ?? null, fn ($q, $segment) => match ($segment) {
                'new' => $q->where('clients.created_at', '>=', now()->startOfMonth()),
                'regular' => $q->whereHas('appointments', $done, '>=', 3),
                'upcoming' => $q->whereHas('appointments', $booked),
                // Last came more than 60 days ago, and nothing booked.
                'lapsed' => $q->whereHas('appointments', $done)
                    ->whereDoesntHave('appointments', fn ($a) => $done($a)->where('appointment_date', '>=', now()->subDays(60)->toDateString()))
                    ->whereDoesntHave('appointments', $booked),
                'members' => $q->whereHas('activeMembership'),
                'app' => $q->whereNotNull('clients.user_id'),
                default => $q,
            })
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->whereRaw('coalesce(' . $this->listBranchSql($branchIds) . ', clients.spa_branch_id) = ?', [(int) $id]))
            ->tap(fn ($q) => match ($filters['sort'] ?? null) {
                // Never visited last; among them, the next booking first.
                'recent' => $q->orderByRaw('last_visit_date desc nulls last')->orderByRaw('next_visit_date asc nulls last'),
                'visits' => $q->orderByDesc('visits_count'),
                'spent' => $q->orderByRaw('visits_total desc nulls last'),
                'newest' => $q->orderByDesc('clients.created_at'),
                'points' => $q->orderByDesc('clients.current_points'),
                default => $q,
            })
            ->when(($filters['sort'] ?? null) !== 'newest', fn ($q) => $q->orderBy('first_name')->orderBy('last_name'))
            ->orderBy('clients.id')
            ->paginate($perPage);
    }

    /** The Clients page's figures, over every client in scope (one query). */
    public function summary(int $spaBusinessId, ?array $branchIds = null): array
    {
        $inner = $this->listQuery($spaBusinessId, $branchIds)
            ->withExists('activeMembership as has_membership')
            ->toBase();
        $month = now()->startOfMonth();
        $lastMonth = $month->copy()->subMonth();
        $lapsedBefore = now()->subDays(60)->toDateString();

        $row = DB::query()->fromSub($inner, 'c')->selectRaw(
            'count(*) as clients,'
            . ' sum(case when c.user_id is not null then 1 else 0 end) as app,'
            . ' sum(case when c.has_membership then 1 else 0 end) as members,'
            . ' sum(case when c.created_at >= ? then 1 else 0 end) as new_this_month,'
            . ' sum(case when c.created_at >= ? and c.created_at < ? then 1 else 0 end) as new_last_month,'
            . ' sum(case when c.visits_count >= 3 then 1 else 0 end) as regulars,'
            . ' sum(case when c.next_visit_date is not null then 1 else 0 end) as upcoming,'
            . ' sum(case when c.last_visit_date is not null and c.next_visit_date is null and c.last_visit_date < ? then 1 else 0 end) as lapsed,'
            . ' coalesce(sum(c.visits_count), 0) as visits, coalesce(sum(c.visits_total), 0) as spent, min(c.next_visit_date) as next_up',
            [$month, $lastMonth, $month, $lapsedBefore],
        )->first();

        return [
            'clients' => (int) $row->clients, 'app' => (int) $row->app, 'members' => (int) $row->members,
            'new' => (int) $row->new_this_month, 'new_last_month' => (int) $row->new_last_month,
            'regular' => (int) $row->regulars, 'upcoming' => (int) $row->upcoming, 'lapsed' => (int) $row->lapsed,
            'visits' => (int) $row->visits, 'spent' => round((float) $row->spent, 2),
            'next_up' => $row->next_up ? substr((string) $row->next_up, 0, 10) : null,
        ];
    }

    public function create(array $payload)
    {
        return Client::create($payload);
    }

    // Business-scoping is the caller's responsibility (see
    // ClientService::updateClient, which resolves via findByUuidForBusiness
    // first) — same convention as StaffRepository::update.
    public function update(string $uuid, array $payload)
    {
        $model = Client::where('uuid', $uuid)->firstOrFail();
        $model->update($payload);
        return $model;
    }

    // $branchIds (manager / front officer) further limits it to that branch's
    // clients — anyone else 404s, same as a client of another business.
    public function findByUuidForBusiness(string $uuid, int $spaBusinessId, ?array $branchIds = null)
    {
        return Client::with(['user', 'activeMembership.program'])
            ->withCount(['vouchers as vouchers_available_count' => fn ($q) => $q->where('status', VoucherStatus::Available->value)
                ->where(fn ($e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now())),
                // Missed bookings — the no-show policy counts these.
                'appointments as no_show_count' => fn ($q) => $q->missedSinceLastVisit()])
            ->where('uuid', $uuid)
            ->where('spa_business_id', $spaBusinessId)
            ->when($branchIds !== null, fn ($q) => $this->scopeToBranches($q, $branchIds))
            ->firstOrFail();
    }

    // A branch's clients: created there, or booked there at least once. The
    // record itself stays business-wide (one client, many branches) — this
    // only limits who a branch's staff can see.
    private function scopeToBranches($query, array $branchIds)
    {
        return $query->where(fn ($q) => $q->whereIn('spa_branch_id', $branchIds)
            ->orWhereHas('appointments', fn ($a) => $a->whereIn('spa_branch_id', $branchIds)));
    }

    public function findForUser(int $spaBusinessId, int $userId)
    {
        return Client::where('spa_business_id', $spaBusinessId)
            ->where('user_id', $userId)
            ->first();
    }

    // Dedup check before creating a new client — matched on phone or email,
    // whichever is provided, scoped to the business (rule: never duplicate
    // client records). See ClientService::findOrCreate.
    public function findByContact(int $spaBusinessId, ?string $phone, ?string $email)
    {
        if (! $phone && ! $email) {
            return null;
        }

        return Client::where('spa_business_id', $spaBusinessId)
            ->where(function ($q) use ($phone, $email) {
                if ($phone) {
                    $q->orWhere('phone_number', $phone);
                }
                if ($email) {
                    $q->orWhere('email', $email);
                }
            })
            ->first();
    }
}

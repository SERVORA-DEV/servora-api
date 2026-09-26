<?php

namespace App\Repository\Business;

use App\Models\Client;
use Illuminate\Support\Facades\DB;

class ClientRepository
{
    // Front-desk client search — name/phone/email, business-scoped. Used by
    // both the "search existing client" appointment-creation step and any
    // standalone client lookup.
    public function search(int $spaBusinessId, ?string $query, int $perPage = 15, ?array $branchIds = null)
    {
        return Client::with(['user', 'preferredTherapist'])
            ->where('spa_business_id', $spaBusinessId)
            ->where('is_active', true)
            ->when($branchIds !== null, fn ($q) => $this->scopeToBranches($q, $branchIds))
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
            ->orderBy('first_name')
            ->paginate($perPage);
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
        return Client::with(['user', 'preferredTherapist'])
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

    // Business-scoping is the caller's responsibility (see
    // ClientService::setPreferredTherapist) — same convention as update().
    public function setPreferredTherapist(string $uuid, ?int $staffId)
    {
        $model = Client::where('uuid', $uuid)->firstOrFail();
        $model->update(['preferred_staff_id' => $staffId]);
        return $model->load('preferredTherapist');
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

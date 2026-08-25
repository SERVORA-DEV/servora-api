<?php

namespace App\Repository\Business;

use App\Models\Client;

class ClientRepository
{
    // Front-desk client search — name/phone/email, business-scoped. Used by
    // both the "search existing client" appointment-creation step and any
    // standalone client lookup.
    public function search(int $spaBusinessId, ?string $query, int $perPage = 15)
    {
        return Client::with('user')
            ->where('spa_business_id', $spaBusinessId)
            ->where('is_active', true)
            ->when($query, function ($q) use ($query) {
                $q->where(function ($inner) use ($query) {
                    $inner->where('first_name', 'like', "%{$query}%")
                        ->orWhere('last_name', 'like', "%{$query}%")
                        ->orWhere('phone_number', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%")
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$query}%"]);
                });
            })
            ->orderBy('first_name')
            ->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Client::create($payload);
    }

    public function findByUuidForBusiness(string $uuid, int $spaBusinessId)
    {
        return Client::with('user')
            ->where('uuid', $uuid)
            ->where('spa_business_id', $spaBusinessId)
            ->firstOrFail();
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

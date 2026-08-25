<?php

namespace App\Service\Business;

use App\Models\User;
use App\Repository\Business\ClientRepository;
use App\Repository\SpaBusinessRepository;
use App\Http\Resources\ClientResource;

class ClientService
{
    private ClientRepository $clientRepository;
    private SpaBusinessRepository $spaBusinessRepository;

    public function __construct(ClientRepository $clientRepository, SpaBusinessRepository $spaBusinessRepository)
    {
        $this->clientRepository = $clientRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
    }

    public function search(User $user, ?string $query, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        return ClientResource::collection($this->clientRepository->search($business->id, $query, $perPage));
    }

    public function createClient(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $client = $this->findOrCreate($business->id, $payload);

        return new ClientResource($client);
    }

    // Search-before-create: never duplicate a client record. Matched by
    // phone or email (whichever is provided) within the same business —
    // an existing match is returned as-is (not updated) so a front-desk
    // typo in a walk-in's other fields doesn't silently overwrite a real
    // client record.
    public function findOrCreate(int $spaBusinessId, array $payload)
    {
        $existing = $this->clientRepository->findByContact(
            $spaBusinessId,
            $payload['phone_number'] ?? null,
            $payload['email'] ?? null,
        );

        if ($existing) {
            return $existing;
        }

        $payload['spa_business_id'] = $spaBusinessId;
        $payload['is_active'] = $payload['is_active'] ?? true;

        return $this->clientRepository->create($payload);
    }
}

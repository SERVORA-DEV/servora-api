<?php

namespace App\Service\Business;

use App\Models\Staff;
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

    public function show(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        return new ClientResource($this->clientRepository->findByUuidForBusiness($uuid, $business->id));
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

    public function updateClient(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // 404s if this uuid isn't (or isn't a client of) this user's own
        // business — update($uuid, ...) alone wouldn't scope that check.
        // Front Office is the only role with client_update (see
        // config/permission.php); Owner/Manager never reach this far.
        $this->clientRepository->findByUuidForBusiness($uuid, $business->id);

        $client = $this->clientRepository->update($uuid, $payload);

        return new ClientResource($client);
    }

    // Manager and front_officer both reach this (client_therapist_manage),
    // independent of client_update — see config/permission.php. Scoped
    // business-wide (via the staff's branch->spa_business_id), not to the
    // caller's own branch, since a client can reasonably prefer a therapist
    // at a different branch of the same business — same business-wide scope
    // findByUuidForBusiness already uses for the client itself.
    public function setPreferredTherapist(User $user, string $clientUuid, ?string $staffUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // 404s if this uuid isn't (or isn't a client of) this user's own
        // business, same guard updateClient uses.
        $this->clientRepository->findByUuidForBusiness($clientUuid, $business->id);

        $staffId = null;

        if ($staffUuid) {
            $staff = Staff::where('uuid', $staffUuid)
                ->whereHas('branch', fn ($q) => $q->where('spa_business_id', $business->id))
                ->first();

            if (! $staff) {
                return response()->json(['message' => 'This therapist could not be found for your business.'], 422);
            }

            if ($staff->role !== 'therapist') {
                return response()->json(['message' => 'Only staff with the Therapist role can be set as a preferred therapist.'], 422);
            }

            $staffId = $staff->id;
        }

        $client = $this->clientRepository->setPreferredTherapist($clientUuid, $staffId);

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

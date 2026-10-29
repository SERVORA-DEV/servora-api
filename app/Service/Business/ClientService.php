<?php

namespace App\Service\Business;

use App\Models\Client;
use App\Models\Staff;
use App\Models\User;
use App\Support\ClientProfile;
use App\Repository\Business\ClientRepository;
use App\Repository\SpaBusinessRepository;
use App\Http\Resources\ClientResource;

class ClientService
{
    private ClientRepository $clientRepository;
    private SpaBusinessRepository $spaBusinessRepository;

    // Works out the preferred therapist of a client nobody picked one for
    // (the one they ask for most), before a client is returned.
    private ClientPreferenceService $preferences;

    public function __construct(ClientRepository $clientRepository, SpaBusinessRepository $spaBusinessRepository, ClientPreferenceService $preferences)
    {
        $this->clientRepository = $clientRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->preferences = $preferences;
    }

    // Owner: the whole business (null = no branch limit). Manager / front
    // officer: only their own branch's clients.
    public function branchScope(User $user): ?array
    {
        return $user->role === 'business_owner'
            ? null
            : $this->spaBusinessRepository->branchIdsForUser($user);
    }

    public function search(User $user, ?string $query, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $clients = $this->clientRepository->search($business->id, $query, $perPage, $this->branchScope($user));
        $this->preferences->attach($clients->getCollection());

        return ClientResource::collection($clients);
    }

    public function show(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $client = $this->clientRepository->findByUuidForBusiness($uuid, $business->id, $this->branchScope($user));
        $this->preferences->attach([$client]);

        return new ClientResource($client);
    }

    public function createClient(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // Staff add clients at their own branch; the owner's aren't tied to one.
        $client = $this->findOrCreate($business->id, $payload, $this->branchScope($user)[0] ?? null);

        return new ClientResource($client);
    }

    public function updateClient(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // 404s if this uuid isn't a client of this user's own business (or,
        // for branch staff, of their branch) — update($uuid, ...) alone
        // wouldn't scope that check. Manager and front office hold
        // client_update (config/permission.php).
        $this->clientRepository->findByUuidForBusiness($uuid, $business->id, $this->branchScope($user));

        $client = $this->clientRepository->update($uuid, $this->profileColumns($payload));
        $this->preferences->attach([$client]);

        return new ClientResource($client);
    }

    /**
     * Takes the client's profile picture down at this spa: it shows as the
     * default avatar from now on. The picture itself stays on the client's
     * account (it is theirs, shared with every spa and their app); only this
     * very picture is hidden here, a newer upload shows again. Scoped like
     * updateClient (client_update).
     */
    public function dismissPhoto(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $client = $this->clientRepository->findByUuidForBusiness($uuid, $business->id, $this->branchScope($user));

        if (! Client::hasPhotoDismissal()) {
            return response()->json(['message' => 'Hiding a profile picture is not available yet.'], 422);
        }

        $photo = $client->user?->profile_photo;
        if (blank($photo) || $client->dismissed_photo === $photo) {
            return response()->json(['message' => 'No profile picture to remove.'], 422);
        }

        $client->update(['dismissed_photo' => $photo, 'photo_dismissed_at' => now()]);
        $this->preferences->attach([$client]);

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
        // business or branch, same guard updateClient uses.
        $this->clientRepository->findByUuidForBusiness($clientUuid, $business->id, $this->branchScope($user));

        $staffId = null;

        if ($staffUuid) {
            $staff = Staff::where('uuid', $staffUuid)
                ->whereHas('branch', fn ($q) => $q->where('spa_business_id', $business->id))
                ->first();

            if (! $staff) {
                return response()->json(['message' => 'This staff member could not be found for your business.'], 422);
            }

            if ($staff->role !== 'therapist') {
                return response()->json(['message' => 'Only staff who perform services can be set as a client\'s preferred staff.'], 422);
            }

            $staffId = $staff->id;
        }

        // No therapist given = stop choosing by hand: the preference goes
        // back to the one their bookings ask for most.
        $client = $this->clientRepository->setPreferredTherapist($clientUuid, $staffId);
        $this->preferences->attach([$client]);

        return new ClientResource($client);
    }

    // Search-before-create: never duplicate a client record. Matched by
    // phone or email (whichever is provided) within the same business —
    // an existing match is returned as-is (not updated) so a front-desk
    // typo in a walk-in's other fields doesn't silently overwrite a real
    // client record.
    /**
     * `gender` and `age` as a form sends them, turned into the columns kept:
     * a clean gender and a birth year (App\Support\ClientProfile). Only what
     * was sent is touched; both are dropped until clients have them.
     */
    public function profileColumns(array $payload): array
    {
        $hasAge = array_key_exists('age', $payload);
        $age = $payload['age'] ?? null;
        unset($payload['age']);

        if (! Client::hasProfile()) {
            unset($payload['gender']);

            return $payload;
        }

        if (array_key_exists('gender', $payload)) {
            $payload['gender'] = ClientProfile::gender($payload['gender']);
        }
        if ($hasAge) {
            $payload['birth_year'] = ClientProfile::birthYear($age, (int) now()->year);
        }

        return $payload;
    }

    /**
     * Fills in what a client record doesn't have yet — a gender, an age, a
     * phone number — from what was just given. Never replaces what is there.
     */
    public function completeProfile(Client $client, array $details): Client
    {
        $given = $this->profileColumns(array_intersect_key($details, array_flip(['gender', 'age'])));
        $fill = [];
        foreach (['gender', 'birth_year'] as $column) {
            if (($given[$column] ?? null) !== null && $client->{$column} === null) {
                $fill[$column] = $given[$column];
            }
        }
        if (filled($details['phone_number'] ?? null) && blank($client->phone_number)) {
            $fill['phone_number'] = trim($details['phone_number']);
        }
        if ($fill) {
            $client->update($fill);
        }

        return $client;
    }

    public function findOrCreate(int $spaBusinessId, array $payload, ?int $spaBranchId = null)
    {
        $existing = $this->clientRepository->findByContact(
            $spaBusinessId,
            $payload['phone_number'] ?? null,
            $payload['email'] ?? null,
        );

        if ($existing) {
            // Already known by this phone or email: what was just typed
            // fills in anything their record is missing.
            return $this->completeProfile($existing, $payload);
        }

        $payload = $this->profileColumns($payload);
        $payload['spa_business_id'] = $spaBusinessId;
        $payload['spa_branch_id'] = $spaBranchId;
        $payload['is_active'] = $payload['is_active'] ?? true;

        return $this->clientRepository->create($payload);
    }

    // Mobile self-booking: resolves the business-scoped client record for a
    // logged-in client user. Prefers a row already linked to this user, then
    // links a front-desk-created row matching their phone/email (so walk-in
    // history carries over), and only creates a new row as a last resort.
    // Like findOrCreate, an existing row's name/phone is never overwritten.
    public function findOrCreateForUser(int $spaBusinessId, User $user, array $details)
    {
        // What the app sent about them, with the account's own details
        // standing in for anything it left out.
        $profile = [
            'gender' => $details['gender'] ?? $user->gender,
            'age' => $details['age'] ?? (filled($user->birth_date)
                ? ClientProfile::age((int) \Illuminate\Support\Carbon::parse($user->birth_date)->year, (int) now()->year)
                : null),
            'phone_number' => $details['phone_number'] ?? null,
        ];

        $linked = $this->clientRepository->findForUser($spaBusinessId, $user->id);
        if ($linked) {
            return $this->completeProfile($linked, $profile);
        }

        $existing = $this->clientRepository->findByContact(
            $spaBusinessId,
            $details['phone_number'] ?? null,
            $user->email,
        );

        // Only claim a walk-in record nobody owns yet. A record already
        // linked to a different account (a shared or recycled phone number)
        // must not be reused — the booking would land in that other
        // person's account and vanish from this one.
        if ($existing && (! $existing->user_id || $existing->user_id === $user->id)) {
            if (! $existing->user_id) {
                $existing->update(['user_id' => $user->id]);
            }

            return $this->completeProfile($existing, $profile);
        }

        return $this->clientRepository->create($this->profileColumns([
            'spa_business_id' => $spaBusinessId,
            'user_id' => $user->id,
            'first_name' => $details['first_name'],
            'last_name' => $details['last_name'],
            'gender' => $profile['gender'],
            'age' => $profile['age'],
            'phone_number' => $details['phone_number'] ?? null,
            'email' => $user->email,
            'is_active' => true,
        ]));
    }
}

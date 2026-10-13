<?php

namespace App\Service\Business;

use App\Http\Resources\AddonResource;
use App\Models\Addon;
use App\Models\Service;
use App\Models\User;
use App\Repository\Business\AddonRepository;
use App\Repository\SpaBusinessRepository;
use App\Support\ServiceAddon;
use Illuminate\Support\Facades\DB;

// The owner's add-ons: the extras the front desk can put on a service during
// a visit (App\Models\Addon). Each says which service categories it goes
// with, and either every service in them or only the ones ticked — the rules
// are in App\Support\ServiceAddon. What happens on a visit is in
// AppointmentService::addAddon.
class AddonService
{
    public function __construct(
        private AddonRepository $addonRepository,
        private SpaBusinessRepository $spaBusinessRepository,
    ) {
    }

    public function listAddons(User $user)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }
        // Before the migration there simply are none (the page says so).
        if (! Addon::ready()) {
            return response()->json(['data' => [], 'ready' => false]);
        }

        return AddonResource::collection($this->addonRepository->listForBusiness($business->id))
            ->additional(['ready' => true]);
    }

    public function getAddon(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business || ! Addon::ready()) {
            return response()->json(['message' => 'Add-on not found.'], 404);
        }

        return new AddonResource($this->addonRepository->findByUuidForBusiness($uuid, $business->id));
    }

    public function createAddon(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        return DB::transaction(function () use ($user, $business, $payload) {
            $services = $this->chosenServices($business->id, $payload, null);
            if (! is_array($services)) {
                return $services;
            }

            $addon = $this->addonRepository->create([
                'spa_business_id' => $business->id,
                'created_by' => $user->id,
                'name' => trim($payload['name']),
                'description' => $payload['description'] ?? null,
                'price' => $payload['price'],
                'duration_minutes' => (int) $payload['duration_minutes'],
                'categories' => array_values($payload['categories']),
                'all_services' => (bool) ($payload['all_services'] ?? true),
                'is_active' => (bool) ($payload['is_active'] ?? true),
            ]);
            $addon->services()->sync($services);

            return new AddonResource($addon->load('services:id,uuid'));
        });
    }

    public function updateAddon(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        return DB::transaction(function () use ($business, $uuid, $payload) {
            $addon = $this->addonRepository->findByUuidForBusiness($uuid, $business->id);

            // Where it is available is only looked at again when the save
            // changes it — the on/off switch alone never is refused for it.
            $moves = array_intersect(['categories', 'all_services', 'service_uuids'], array_keys($payload)) !== [];
            $services = $moves ? $this->chosenServices($business->id, $payload, $addon) : [];
            if (! is_array($services)) {
                return $services;
            }

            $fields = array_intersect_key($payload, array_flip(['name', 'description', 'price', 'duration_minutes', 'categories', 'all_services', 'is_active']));
            if (isset($fields['name'])) {
                $fields['name'] = trim($fields['name']);
            }
            if (isset($fields['categories'])) {
                $fields['categories'] = array_values($fields['categories']);
            }
            $addon->update($fields);
            if ($moves) {
                $addon->services()->sync($services);
            }

            return new AddonResource($addon->load('services:id,uuid'));
        });
    }

    public function deleteAddon(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business || ! Addon::ready()) {
            return response()->json(['message' => 'Add-on not found.'], 404);
        }

        // Soft-deleted: visits that had it keep their own copy of it anyway.
        $this->addonRepository->findByUuidForBusiness($uuid, $business->id)->delete();

        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    // The service ids to keep on the add-on after this save — none when it
    // goes with every service in its categories. A list that wasn't sent
    // keeps what is there. Refused (a 422 is returned in place of the list)
    // when "only these services" has none, or one that isn't this business's
    // or isn't in the add-on's categories.
    private function chosenServices(int $businessId, array $payload, ?Addon $existing)
    {
        $all = (bool) ($payload['all_services'] ?? $existing?->all_services ?? true);
        if ($all) {
            return [];
        }

        $categories = $payload['categories'] ?? (array) ($existing?->categories ?? []);
        $query = Service::where('spa_business_id', $businessId)->where('is_template', false);
        $chosen = array_key_exists('service_uuids', $payload)
            ? $query->whereIn('uuid', $payload['service_uuids'])->get(['id', 'name', 'category'])
            : ($existing ? $query->whereIn('id', $existing->services->pluck('id'))->get(['id', 'name', 'category']) : collect());

        if (array_key_exists('service_uuids', $payload) && $chosen->count() !== count(array_unique($payload['service_uuids']))) {
            return response()->json(['message' => 'One or more of the chosen services are not on your menu.'], 422);
        }
        if ($chosen->isEmpty()) {
            return response()->json(['message' => 'Choose at least one service, or make it available for every service in its categories.'], 422);
        }

        $outside = ServiceAddon::outsideCategories($categories, $chosen->pluck('category', 'id')->all());
        if ($outside) {
            $names = $chosen->whereIn('id', $outside)->pluck('name')->implode(', ');

            return response()->json(['message' => "Not in the chosen categories: {$names}. Add the category, or untick the service."], 422);
        }

        return $chosen->pluck('id')->all();
    }
}

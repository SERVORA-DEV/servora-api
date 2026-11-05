<?php

namespace App\Service\Business;

use App\Http\Resources\SpaBranchResource;
use App\Models\BranchPackage;
use App\Models\BranchService;
use App\Models\CustomerProgram;
use App\Models\Facility;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Support\AppCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchCloneService
{
    public function __construct(private SpaBranchService $branches) {}

    public function clone(User $owner, string $uuid, array $payload): JsonResponse
    {
        $business = SpaBusiness::where('owner_id', $owner->id)->firstOrFail();

        return DB::transaction(function () use ($owner, $uuid, $payload, $business) {
            // Same lock and capacity check as ordinary creation. Keep the lock
            // until every selected setting has been copied (or rolled back).
            SpaBusiness::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $source = SpaBranch::where('spa_business_id', $business->id)->where('uuid', $uuid)->firstOrFail();
            if ($source->verification_status === 'Unregistered') {
                throw ValidationException::withMessages(['source' => 'Finish registering the source branch before copying it.']);
            }

            $result = $this->branches->createSpaBranch($owner, $payload['branch']);
            if ($result instanceof JsonResponse) {
                return $result;
            }
            $target = $result->resource;
            $copy = $payload['copy'];
            $copied = array_fill_keys(['facilities', 'offerings', 'hours', 'booking_rules', 'programs'], 0);

            if ($copy['facilities']) {
                $facilities = $source->facilities()->with('services')->get();
                $names = $payload['facility_names'] ?? [];
                if (array_diff(array_keys($names), $facilities->pluck('uuid')->all())) {
                    throw ValidationException::withMessages(['facility_names' => 'Only facilities belonging to the source branch can be renamed.']);
                }
                $used = [];
                foreach ($facilities as $facility) {
                    $name = trim($names[$facility->uuid] ?? $facility->name);
                    $key = mb_strtolower($name);
                    if ($key === '' || isset($used[$key])) {
                        throw ValidationException::withMessages(['facility_names' => 'Give each copied facility a different, non-empty name.']);
                    }
                    $used[$key] = true;
                    $new = $target->facilities()->create([
                        ...Arr::only($facility->getAttributes(), ['description', 'category', 'status']),
                        'name' => $name,
                        'amenities' => $facility->amenities,
                        'is_available' => $facility->is_available,
                        ...(Facility::hasClientLimit() ? ['max_clients' => $facility->clientLimit()] : []),
                    ]);
                    $new->services()->sync($facility->services->where('spa_business_id', $business->id)->modelKeys());
                    $copied['facilities']++;
                }
            }

            if ($copy['offerings']) {
                foreach ($source->branchServices()->whereHas('serviceVariant.service', fn ($q) => $q->where('spa_business_id', $business->id))->get() as $offering) {
                    BranchService::create([
                        'spa_branch_id' => $target->id,
                        ...Arr::only($offering->getAttributes(), ['service_variant_id', 'custom_price', 'is_available']),
                    ]);
                    $copied['offerings']++;
                }
                foreach ($source->branchPackages()->whereHas('package', fn ($q) => $q->where('spa_business_id', $business->id))->get() as $offering) {
                    BranchPackage::create([
                        'spa_branch_id' => $target->id,
                        ...Arr::only($offering->getAttributes(), ['package_id', 'custom_price', 'is_available']),
                    ]);
                    $copied['offerings']++;
                }
            }

            if ($copy['hours']) {
                foreach ($source->schedules as $schedule) {
                    $target->schedules()->create(Arr::only($schedule->getAttributes(), ['day_of_week', 'opening_time', 'closing_time', 'is_closed']));
                    $copied['hours']++;
                }
            }
            if ($copy['booking_rules']) {
                $target->update(['booking_overrides' => $source->booking_overrides]);
                $copied['booking_rules'] = empty($source->booking_overrides) ? 0 : 1;
            }
            if ($copy['programs']) {
                $programs = CustomerProgram::where('spa_business_id', $business->id)
                    ->whereHas('branches', fn ($q) => $q->where('spa_branches.id', $source->id))->get();
                foreach ($programs as $program) {
                    $program->branches()->syncWithoutDetaching([$target->id]);
                    if ($program->only_branch_id !== null) {
                        $program->update(['only_branch_id' => null]);
                    }
                    $copied['programs']++;
                }
            }

            DB::afterCommit(fn () => AppCache::bumpBusiness($business->id));

            return response()->json([
                'data' => (new SpaBranchResource($target->load('schedules')))->resolve(),
                'copied' => $copied,
            ], 201);
        });
    }
}

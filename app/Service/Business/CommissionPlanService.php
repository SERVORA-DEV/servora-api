<?php

namespace App\Service\Business;

use App\Http\Resources\CommissionPlanResource;
use App\Models\CommissionPlan;
use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use Illuminate\Http\JsonResponse;

// The owner's commission plans (App\Models\CommissionPlan): named rates given
// to staff. Reading them is open to the owner and managers (a manager may
// give one to a staff member, if their account may); creating, renaming and
// deleting are the owner's alone (routes/api.php).
//
// A plan's rate is in the kind Staff Policies sets, so it is held to the same
// limits (a percentage is at most 100). Changing a rate applies to services
// completed afterwards: what was earned stays as it was worked out
// (App\Support\History, CommissionCalculator::freeze).
class CommissionPlanService
{
    /** The standard rate's own name — taken, so no plan can use it. */
    public const STANDARD = 'Standard';

    public function __construct(private SpaBusinessRepository $spaBusinessRepository)
    {
    }

    public function list(User $user)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business || ! CommissionPlan::ready()) {
            return response()->json(['data' => []]);
        }

        return CommissionPlanResource::collection(
            CommissionPlan::where('spa_business_id', $business->id)->withCount('staff')->orderBy('name')->get()
        );
    }

    public function create(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }
        if (! CommissionPlan::ready()) {
            return response()->json(['message' => 'Commission plans are not available yet.'], 422);
        }

        $clean = $this->clean($business, $payload, null);
        if ($clean instanceof JsonResponse) {
            return $clean;
        }

        $plan = CommissionPlan::create($clean + ['spa_business_id' => $business->id]);

        return (new CommissionPlanResource($plan->loadCount('staff')))->response()->setStatusCode(201);
    }

    public function update(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        $plan = $business ? $this->find($business, $uuid) : null;
        if (! $plan) {
            return response()->json(['message' => 'Commission plan not found.'], 404);
        }

        $clean = $this->clean($business, $payload, $plan);
        if ($clean instanceof JsonResponse) {
            return $clean;
        }
        $plan->update($clean);

        return new CommissionPlanResource($plan->loadCount('staff'));
    }

    public function delete(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        $plan = $business ? $this->find($business, $uuid) : null;
        if (! $plan) {
            return response()->json(['message' => 'Commission plan not found.'], 404);
        }

        $using = $plan->staff()->count();
        if ($using > 0) {
            return response()->json([
                'message' => "{$using} staff " . ($using === 1 ? 'is' : 'are') . " on {$plan->name}. Move them to another plan first.",
            ], 422);
        }
        $plan->delete();

        return response()->json(['message' => 'Commission plan deleted.']);
    }

    // The name and rate on their way in: the name trimmed and free (case
    // aside) within this business, the rate fitting the kind Staff Policies
    // sets. A JSON 422 in place of the values when something is wrong.
    private function clean(SpaBusiness $business, array $payload, ?CommissionPlan $existing)
    {
        $out = [];

        if (array_key_exists('name', $payload)) {
            $name = trim(preg_replace('/\s+/', ' ', (string) $payload['name']));
            if ($name === '') {
                return $this->refuse('Give the plan a name.', 'name');
            }
            if (mb_strtolower($name) === mb_strtolower(self::STANDARD)) {
                return $this->refuse('"Standard" is the standard rate in Staff Policies — pick another name.', 'name');
            }
            $taken = CommissionPlan::where('spa_business_id', $business->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->exists();
            if ($taken) {
                return $this->refuse("There is already a plan called \"{$name}\".", 'name');
            }
            $out['name'] = $name;
        }

        if (array_key_exists('rate', $payload)) {
            $rate = round((float) $payload['rate'], 2);
            $business->loadMissing('settings');
            $policy = $business->settings?->section('staff_policy') ?? SpaBusinessSetting::DEFAULTS['staff_policy'];
            if (($policy['commission_type'] ?? 'percentage') !== 'fixed' && $rate > 100) {
                return $this->refuse('A percentage commission can be at most 100%.', 'rate');
            }
            $out['rate'] = $rate;
        }

        return $out;
    }

    private function find(SpaBusiness $business, string $uuid): ?CommissionPlan
    {
        return CommissionPlan::where('spa_business_id', $business->id)->where('uuid', $uuid)->first();
    }

    private function refuse(string $message, string $field): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}

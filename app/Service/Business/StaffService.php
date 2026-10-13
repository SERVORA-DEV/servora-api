<?php

namespace App\Service\Business;

use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Repository\Business\StaffRepository;
use App\Repository\Business\StaffServiceRepository as StaffQualificationRepository;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Http\Resources\StaffResource;
use App\Models\SpaBusiness;
use App\Support\CommissionShare;
use App\Support\StaffSpecialty;

class StaffService
{
    private StaffRepository $staffRepository;
    private SpaBranchRepository $spaBranchRepository;
    private SpaBusinessRepository $spaBusinessRepository;
    private StaffQualificationRepository $staffQualificationRepository;

    public function __construct(
        StaffRepository $staffRepository,
        SpaBranchRepository $spaBranchRepository,
        SpaBusinessRepository $spaBusinessRepository,
        StaffQualificationRepository $staffQualificationRepository,
        private ManagerChangeNotifier $managerChanges,
        private CommissionCalculator $commissionCalculator,
    ) {
        $this->staffRepository = $staffRepository;
        $this->spaBranchRepository = $spaBranchRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->staffQualificationRepository = $staffQualificationRepository;
    }

    // business_owner's branch ids cover the whole business; manager's cover
    // only their own staff record's branch — see
    // SpaBusinessRepository::branchesForUser. Every method below scopes
    // through this rather than $business->id, so a manager can only ever
    // list/view/create/update/delete staff at their own branch.
    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchIdsForUser($user);
    }

    // For an owner who asked to hear when a manager changes the staff.
    // $what follows the manager's name; {name} and {branch} are filled in.
    private function staffChanged(User $user, Staff $staff, string $title, string $what, bool $gone = false): void
    {
        $this->managerChanges->tell(
            $user, fn () => $staff->branch, ManagerChangeNotifier::STAFF, $title,
            fn () => strtr($what, [
                '{name}' => trim("{$staff->first_name} {$staff->last_name}") ?: 'an employee',
                '{branch}' => $staff->branch->branch_name,
            ]),
            // A click opens the employee, unless they were just removed.
            reference: $gone ? null : $staff->uuid,
        );
    }

    public function listStaff(User $user, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $collection = $this->staffRepository->paginateForBranches($this->branchIds($user), $perPage);
        return StaffResource::collection($collection)->additional($this->standardCommission($business));
    }

    // The standard commission (Staff Policies), beside every staff answer:
    // what someone with no rate of their own earns, and whether a custom one
    // can be set at all yet.
    private function standardCommission(SpaBusiness $business): array
    {
        $rule = $this->commissionCalculator->describe($business);

        return ['commission' => [
            'enabled' => $rule['enabled'],
            'type' => $rule['type'],
            'standard_rate' => $rule['standard_rate'],
            'custom_allowed' => Staff::hasCustomCommission(),
        ]];
    }

    private function answer(Staff $staff, SpaBusiness $business): StaffResource
    {
        return (new StaffResource($staff))->additional($this->standardCommission($business));
    }

    // Someone's own commission rate on its way to the database: left out
    // until the column exists; none for a manager or the front desk; for
    // staff, the number sent, which must fit the kind the standard is (a 422
    // in place of the payload when it doesn't). Blank means the standard.
    private function withCommission(array $payload, ?Staff $existing, SpaBusiness $business)
    {
        if (! Staff::hasCustomCommission()) {
            unset($payload['commission_rate']);

            return $payload;
        }

        $role = $payload['role'] ?? $existing?->role;
        if ($role !== StaffSpecialty::ROLE) {
            // No longer someone who performs services: nothing of their own.
            if (array_key_exists('commission_rate', $payload) || ($existing && $existing->commission_rate !== null)) {
                $payload['commission_rate'] = null;
            }

            return $payload;
        }
        if (! array_key_exists('commission_rate', $payload)) {
            return $payload;
        }

        $policy = $this->commissionCalculator->policy($business) ?? $business->settings?->section('staff_policy');
        [$rate, $problem] = CommissionShare::clean($payload['commission_rate'], $policy['commission_type'] ?? 'percentage');
        if ($problem) {
            return response()->json(['message' => $problem, 'errors' => ['commission_rate' => [$problem]]], 422);
        }
        $payload['commission_rate'] = $rate;

        return $payload;
    }

    // "Standard (15%)" / "20%" — someone's commission as a sentence reads it.
    private function commissionWords(SpaBusiness $business, ?float $ownRate): string
    {
        $policy = $this->commissionCalculator->policy($business) ?? $business->settings?->section('staff_policy') ?? \App\Models\SpaBusinessSetting::DEFAULTS['staff_policy'];

        return CommissionShare::describe($policy, $ownRate);
    }

    // What someone is paid is the owner's to know about, and nobody else's:
    // a manager's change to it always reaches the owner, on its own.
    private function commissionChanged(User $user, Staff $staff, SpaBusiness $business, ?float $before): void
    {
        $after = $staff->ownCommissionRate();
        if ($before === $after) {
            return;
        }

        $this->managerChanges->tellOwner(
            $user, fn () => $staff->branch, 'Employee Changed',
            fn () => sprintf(
                "changed %s's commission from %s to %s at %s.",
                trim("{$staff->first_name} {$staff->last_name}") ?: 'an employee',
                $this->commissionWords($business, $before),
                $this->commissionWords($business, $after),
                $staff->branch->branch_name,
            ),
            reference: $staff->uuid,
        );
    }

    /**
     * spa_branch_uuid must be one of this user's own accessible branches —
     * resolved server-side (404s otherwise) rather than trusted from the
     * payload, same guard SpaBranchService uses for spa_business_id on
     * branch creation. For a manager this means they can only ever create
     * staff at their own branch, never another branch in the business.
     */
    public function createStaff(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branch = $this->spaBranchRepository->findByUuidForBranches($payload['spa_branch_uuid'], $this->branchIds($user));

        $payload['spa_branch_id'] = $branch->id;
        $payload['status'] = $payload['status'] ?? 'active';
        unset($payload['spa_branch_uuid']);

        $payload = $this->withSpecialties($payload, null);
        if (! is_array($payload)) {
            return $payload;
        }
        $payload = $this->withCommission($payload, null, $business);
        if (! is_array($payload)) {
            return $payload;
        }

        // Auto-assign EMP-0001-style number when none was supplied — StaffRequest
        // already validated a manually-entered number for uniqueness, so this
        // path only runs (and only ever retries) for the generated case: a
        // rare race against another concurrent create on the same branch
        // landing on the same next number.
        $wasSupplied = ! empty($payload['employee_number']);
        $attempts = 0;

        while (true) {
            if (! $wasSupplied) {
                $payload['employee_number'] = $this->staffRepository->nextEmployeeNumber($branch->id);
            }

            try {
                $model = $this->staffRepository->create($payload);
                break;
            } catch (\Illuminate\Database\QueryException $e) {
                $isDuplicateNumber = str_contains($e->getMessage(), 'employee_number');
                if ($wasSupplied || $isDuplicateNumber === false || ++$attempts >= 5) {
                    throw $e;
                }
            }
        }

        $this->staffChanged($user, $model, 'Employee Added', 'added {name} to the staff of {branch}.');
        // Added with a rate of their own: the owner hears that too.
        $this->commissionChanged($user, $model, $business, null);

        return $this->answer($model->load('branch'), $business);
    }

    public function getStaff(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $model = $this->staffRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        return $this->answer($model, $business);
    }

    public function updateStaff(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        // 404s if this uuid isn't (or isn't a staff member of) one of this
        // user's own accessible branches — update($uuid, ...) alone wouldn't
        // scope that check.
        $existing = $this->staffRepository->findByUuidForBranches($uuid, $branchIds);

        // A manager's own staff row is what ties their login to the branch —
        // changing its role or status would lock them out of it.
        if ($existing->user_id === $user->id) {
            $changesRole = isset($payload['role']) && $payload['role'] !== $existing->role;
            $changesStatus = isset($payload['status']) && $payload['status'] !== $existing->status;
            if ($changesRole || $changesStatus) {
                return response()->json(['message' => "You can't change your own role or status. Ask the spa owner."], 422);
            }
        }

        if (! empty($payload['spa_branch_uuid'])) {
            $branch = $this->spaBranchRepository->findByUuidForBranches($payload['spa_branch_uuid'], $branchIds);
            $payload['spa_branch_id'] = $branch->id;
        }
        unset($payload['spa_branch_uuid']);

        $payload = $this->withSpecialties($payload, $existing);
        if (! is_array($payload)) {
            return $payload;
        }
        $payload = $this->withCommission($payload, $existing, $business);
        if (! is_array($payload)) {
            return $payload;
        }
        $rateBefore = $existing->ownCommissionRate();

        $model = $this->staffRepository->update($uuid, $payload);

        $this->staffChanged($user, $model, 'Employee Changed', "changed {name}'s details at {branch}.");
        $this->commissionChanged($user, $model, $business, $rateBefore);

        return $this->answer($model, $business);
    }

    // What the person does (App\Support\StaffSpecialty) on its way to the
    // database: left out until the column exists; none for a manager or the
    // front desk; for staff, what was sent — what they had, or "Therapist",
    // when nothing was. Sending an empty list for someone who performs
    // services is refused (a 422 in place of the payload).
    private function withSpecialties(array $payload, ?Staff $existing)
    {
        if (! Staff::hasSpecialties()) {
            unset($payload['specialties']);

            return $payload;
        }

        $role = $payload['role'] ?? $existing?->role;
        $sent = array_key_exists('specialties', $payload);
        if ($role === \App\Support\StaffSpecialty::ROLE && $sent && ! \App\Support\StaffSpecialty::clean($payload['specialties'])) {
            return response()->json(['message' => 'Choose at least one specialty.'], 422);
        }
        // Nothing to settle: a save that touches neither the role nor these.
        if (! $sent && $existing && ! array_key_exists('role', $payload)) {
            return $payload;
        }

        $payload['specialties'] = \App\Support\StaffSpecialty::forRole($role, $payload['specialties'] ?? null, $existing?->specialties) ?: null;

        return $payload;
    }

    // Read-back for the manager's "Services They Can Perform" card. Kept as
    // its own sub-resource rather than a field on StaffResource for the same
    // reason the weekly schedule is one: the staff list would otherwise pay
    // for a join nothing on that screen renders.
    //
    // An empty array here means "no restrictions configured" — NOT "can
    // perform nothing". See StaffServiceRepository::isQualified for the
    // opt-in-if-configured semantics the whole feature rests on; the UI has
    // to say so out loud, since the two readings are opposites.
    public function services(User $user, string $uuid)
    {
        $staff = $this->staffRepository->findByUuidForBranches($uuid, $this->branchIds($user));

        return [
            'data' => $staff->qualifiedServices()->orderBy('name')->get()->map(fn (Service $service) => [
                'uuid' => $service->uuid,
                'name' => $service->name,
                'category' => $service->category,
            ]),
        ];
    }

    // Replaces this staff member's service qualifications wholesale — see
    // StaffServiceRepository::sync / isQualified for the opt-in-if-configured
    // semantics AppointmentAvailabilityService relies on. Stays in the
    // owner/manager staff-management group (routes/api.php), not the
    // day-to-day front-office group.
    public function updateServices(User $user, string $uuid, array $serviceUuids)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $staff = $this->staffRepository->findByUuidForBranches($uuid, $this->branchIds($user));

        $serviceIds = Service::where('spa_business_id', $business->id)
            ->whereIn('uuid', $serviceUuids)
            ->pluck('id')
            ->all();

        $this->staffQualificationRepository->sync($staff->id, $serviceIds);

        $this->staffChanged($user, $staff, 'Employee Services Changed', 'changed which services {name} can perform at {branch}.');

        return $this->answer($staff->load('branch'), $business);
    }

    public function deleteStaff(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // findByUuidForBranches 404s if this uuid isn't (or isn't a staff
        // member of) one of this user's own accessible branches — delete($uuid)
        // alone wouldn't scope that check.
        $existing = $this->staffRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        if ($existing->user_id === $user->id) {
            return response()->json(['message' => "You can't remove your own staff record. Ask the spa owner."], 422);
        }

        $this->staffRepository->delete($uuid);

        $this->staffChanged($user, $existing, 'Employee Removed', 'removed {name} from the staff of {branch}.', gone: true);

        return true;
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\SpaBranch;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Support\AccountScope;
use App\Support\ActivityRecorder;
use App\Support\BusinessActivityDescriber as Describer;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// The business's Audit Log: one row for every change the owner or one of
// their logins makes (Business → Audit Log). It wraps every business/* route;
// what the request changed is collected while it runs (ActivityRecorder) and
// the row is written once the answer is ready — only for a change that went
// through (a 2xx answer to a POST, PUT, PATCH or DELETE).
//
// The row says, in its own copy (App\Support\History): who, in which role and
// at what level, at which branch, what it was about and a sentence for it
// (BusinessActivityDescriber), with the fields that changed.
class RecordBusinessActivity
{
    private const ROLES = ['business_owner', 'manager', 'front_officer'];

    public function __construct(
        private ActivityRecorder $recorder,
        private SpaBusinessRepository $businesses,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! AuditLog::businessReady()) {
            return $next($request);
        }

        $this->recorder->start();
        try {
            $response = $next($request);
        } finally {
            $this->recorder->stop();
        }

        if ($response->isSuccessful()) {
            try {
                $this->write($request);
            } catch (Throwable $e) {
                // The change itself went through; a missing log row must not
                // turn it into an error.
                report($e);
            }
        }

        return $response;
    }

    private function write(Request $request): void
    {
        $user = $request->user();
        if (! $user instanceof User || ! in_array($user->role, self::ROLES, true)) {
            return;
        }

        $action = class_basename((string) $request->route()?->getActionName());
        $entry = Describer::entry($action);
        if (! $entry) {
            return;
        }
        [$event, $area, $verb, $class, $sentence] = $entry;

        $business = $this->businesses->findForUser($user);
        if (! $business) {
            return;
        }

        $touched = $this->recorder->touched();
        $explicit = $this->recorder->explicit();
        $primary = $this->primary($class, $touched, $request, $verb);
        [$priced, $pricedBranches] = $this->priceDetails($event, $request, $business->id);

        $branchId = match (true) {
            count($pricedBranches) === 1 => $pricedBranches[0],
            count($pricedBranches) > 1 => null,
            default => $this->branchId($primary, $class, $touched, $request, $user, $business->id),
        };
        $branch = $branchId ? SpaBranch::withTrashed()->where('spa_business_id', $business->id)->find($branchId) : null;
        $branchId = $branch?->id;

        $subject = Describer::label($primary);
        $summary = Describer::fill($sentence, [
            'subject' => $subject,
            'amount' => $this->amount($request, $touched, $primary),
            'when' => Describer::when($primary instanceof Appointment ? $primary : null),
            'count' => $priced ? count($priced) . ' ' . (count($priced) === 1 ? 'change' : 'changes') : $this->count($event, $touched),
            'state' => $this->state($request),
        ]);

        [$old, $new] = $this->changes($primary, $touched);
        $new = array_merge($new ?? [], $priced);
        // What a service already wrote down for this request (a refund's
        // reason, a rule overridden) is told in this row too.
        foreach ($explicit as $row) {
            foreach (Describer::clean($row->new_values ?? []) as $key => $value) {
                $new[$key] ??= $value;
            }
        }

        $level = $user->role === 'business_owner'
            ? 'owner'
            : ($branchId ? AccountScope::levelAt($user, $branchId) : null) ?? $this->topLevel($user);

        AuditLog::create([
            'user_id' => $user->id,
            'table_name' => $primary?->getTable() ?? 'spa_businesses',
            'record_id' => $primary?->getKey() ?? ($class ? null : $business->id),
            'action' => $verb,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'spa_business_id' => $business->id,
            'spa_branch_id' => $branchId,
            'event' => $event,
            'area' => $area,
            'summary' => mb_substr($summary, 0, 255),
            'subject_label' => $subject,
            'actor_name' => mb_substr($user->displayName(), 0, 160),
            'actor_role' => $user->role,
            'actor_level' => $level,
            'branch_name' => $branch?->branch_name,
        ]);

        // The rows a service wrote itself stay where they are (an
        // attendance record's history reads them) and now say where.
        $ids = array_filter(array_map(fn (AuditLog $row) => $row->id, $explicit));
        if ($ids) {
            AuditLog::whereIn('id', $ids)->update(['spa_business_id' => $business->id, 'spa_branch_id' => $branchId]);
        }
    }

    /**
     * The record the action is about: the first of its kind the request
     * created (for an add) or touched; else the one the address names.
     */
    private function primary(?string $class, array $touched, Request $request, string $verb): ?Model
    {
        if (! $class) {
            return null;
        }

        $ofClass = array_values(array_filter($touched, fn ($t) => $t['model'] instanceof $class));
        if ($verb === 'Create') {
            foreach ($ofClass as $t) {
                if ($t['kind'] === 'created') {
                    return $t['model'];
                }
            }
        }
        if ($ofClass) {
            return $ofClass[0]['model'];
        }

        // Nothing of that kind was saved (it was changed with a plain query,
        // or the change was to something it holds): the one in the address.
        foreach (array_reverse($request->route()?->parameters() ?? []) as $value) {
            if ($value instanceof $class) {
                return $value;
            }
            if (! is_scalar($value)) {
                continue;
            }
            $query = method_exists($class, 'bootSoftDeletes') ? $class::withTrashed() : $class::query();
            $found = is_numeric($value) ? $query->find((int) $value) : $query->where('uuid', (string) $value)->first();
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Where it happened: the record's own branch; for the catalog, logins and
     * the business's settings, the one branch the change reached if it
     * reached only one; else the branch in the address, the one the screen is
     * working on (X-Branch) or a login's only branch. None: business-wide.
     */
    private function branchId(?Model $primary, ?string $class, array $touched, Request $request, User $user, int $businessId): ?int
    {
        $businessWide = $class && in_array($class, Describer::BUSINESS_WIDE, true);

        if (! $businessWide && ($id = Describer::branchIdOf($primary))) {
            return $id;
        }

        $reached = [];
        foreach ($touched as $t) {
            if ($id = $t['model']->getAttribute('spa_branch_id')) {
                $reached[(int) $id] = true;
            }
        }
        if (count($reached) === 1) {
            return array_key_first($reached);
        }
        if ($businessWide || count($reached) > 1) {
            return null;
        }

        $uri = (string) $request->route()?->uri();
        if (str_starts_with($uri, 'api/business/branch/{')) {
            $value = $request->route('uuid') ?? $request->route('branch');
            if ($value instanceof SpaBranch) {
                return $value->id;
            }
            if ($value) {
                $id = SpaBranch::withTrashed()->where('spa_business_id', $businessId)
                    ->where(is_numeric($value) ? 'id' : 'uuid', $value)->value('id');
                if ($id) {
                    return (int) $id;
                }
            }
        }

        if ($picked = $request->header('X-Branch')) {
            $id = SpaBranch::where('spa_business_id', $businessId)->where('uuid', $picked)->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        if ($user->role !== 'business_owner') {
            $own = AccountScope::allBranches($user);
            if ($own->count() === 1) {
                return $own->first()->id;
            }
        }

        return null;
    }

    /**
     * Branch prices are saved in one go, without model events, so what was
     * set is read from the request: per item at a branch ("Swedish Massage
     * 1h" → "₱1,250"), or per branch for one item ("Serenity Lanang" →
     * "Not offered"). Also the branches it was about.
     *
     * @return array{0: array<string, string>, 1: int[]}
     */
    private function priceDetails(string $event, Request $request, int $businessId): array
    {
        $state = function ($row) {
            if (! filter_var($row['is_available'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return 'Not offered';
            }
            $price = $row['custom_price'] ?? null;

            return $price !== null && $price !== '' ? (Describer::peso($price) ?? '₱0') : 'Standard price';
        };

        $details = [];
        if ($event === 'branch.offerings') {
            $services = (array) $request->input('services', []);
            $packages = (array) $request->input('packages', []);
            $variants = \App\Models\ServiceVariant::with('service:id,name')
                ->whereIn('uuid', array_column($services, 'variant_uuid'))->get()->keyBy('uuid');
            $names = \App\Models\Package::where('spa_business_id', $businessId)
                ->whereIn('uuid', array_column($packages, 'package_uuid'))->pluck('name', 'uuid');
            foreach ($services as $row) {
                $label = Describer::label($variants[$row['variant_uuid'] ?? ''] ?? null) ?? 'A service';
                $details[$label] = $state($row);
            }
            foreach ($packages as $row) {
                $details[$names[$row['package_uuid'] ?? ''] ?? 'A package'] = $state($row);
            }

            return [array_slice($details, 0, 30, true), []];
        }

        if ($event === 'service.branches' || $event === 'package.branches') {
            $rows = (array) $request->input('branches', []);
            $branches = SpaBranch::where('spa_business_id', $businessId)
                ->whereIn('uuid', array_column($rows, 'branch_uuid'))->get(['id', 'uuid', 'branch_name'])->keyBy('uuid');
            foreach ($rows as $row) {
                if ($b = $branches[$row['branch_uuid'] ?? ''] ?? null) {
                    $details[$b->branch_name] = $state($row);
                }
            }

            return [array_slice($details, 0, 30, true), $branches->pluck('id')->all()];
        }

        return [[], []];
    }

    /** The fields that changed on the record, without secrets or book-keeping. */
    private function changes(?Model $primary, array $touched): array
    {
        if (! $primary) {
            return [null, null];
        }
        $old = [];
        $new = [];
        foreach ($touched as $t) {
            if ($t['model'] !== $primary) {
                continue;
            }
            $old += Describer::clean($t['old']);
            $new = array_merge($new, Describer::clean($t['new']));
        }
        // A key whose value ended where it started didn't change.
        foreach ($new as $key => $value) {
            if (array_key_exists($key, $old) && $old[$key] == $value) {
                unset($old[$key], $new[$key]);
            }
        }

        return [$old, $new];
    }

    private function amount(Request $request, array $touched, ?Model $primary): ?string
    {
        $asked = $request->input('amount') ?? $request->input('refund_amount');
        if (is_numeric($asked)) {
            return Describer::peso($asked);
        }
        foreach ($touched as $t) {
            if ($t['model'] instanceof Payment) {
                $refunded = ($t['new']['refunded_amount'] ?? null);
                if ($refunded !== null) {
                    return Describer::peso((float) $refunded - (float) ($t['old']['refunded_amount'] ?? 0));
                }
            }
        }
        if ($primary instanceof Payment) {
            return Describer::peso($primary->amount);
        }

        return null;
    }

    private function count(string $event, array $touched): ?string
    {
        if ($event === 'attendance.mark_all') {
            $n = count(array_filter($touched, fn ($t) => $t['model'] instanceof \App\Models\Attendance));

            return $n ? $n . ' ' . ($n === 1 ? 'person' : 'people') : null;
        }
        if ($event === 'branch.offerings') {
            $n = count(array_filter($touched, fn ($t) => $t['model'] instanceof \App\Models\BranchService || $t['model'] instanceof \App\Models\BranchPackage));

            return $n ? $n . ' ' . ($n === 1 ? 'change' : 'changes') : null;
        }

        return null;
    }

    private function state(Request $request): ?string
    {
        foreach (['enabled', 'is_enabled', 'auto_renew', 'active', 'is_active'] as $key) {
            if ($request->has($key)) {
                return $request->boolean($key) ? 'on' : 'off';
            }
        }

        return null;
    }

    private function topLevel(User $user): ?string
    {
        $levels = array_values(AccountScope::levels($user));
        if (! $levels) {
            return null;
        }

        return \App\Models\AccountBranch::runsBranch($levels) ? 'management' : 'operation';
    }
}

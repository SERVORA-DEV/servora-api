<?php

namespace App\Support;

use App\Models\AccountBranch;
use App\Models\Addon;
use App\Models\Appointment;
use App\Models\AppointmentPackage;
use App\Models\AppointmentServiceItem;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Billing;
use App\Models\BillingAdjustment;
use App\Models\CatalogRequest;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\ClientPointEntry;
use App\Models\ClientVoucher;
use App\Models\CommissionPlan;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\ProgramRequest;
use App\Models\Queue;
use App\Models\StaffPenaltyRate;
use App\Models\StaffScheduleChangeRequest;
use App\Models\StaffTimeOff;
use App\Models\StaffTransferRequest;
use App\Models\Subscription;
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// "Something changed at this business" — and in which part of it. A screen
// that stays open (the front desk, the owner's boards) asks for these cheap
// tokens every few seconds and reads its data again only when one it cares
// about is different, so what another desk, the phone app or a scheduled job
// does shows up within moments instead of at the next slow poll — and a
// payment doesn't make the staff list read itself again.
//
// One token per area (TOPICS), plus one overall token that changes with every
// one of them (what a screen that names no areas follows). Each is a random
// string kept in the cache, replaced whenever a model of that area is saved or
// deleted (register(), called from AppServiceProvider). Asking reads the cache
// and nothing else, so it is safe to ask often. A cache that lost them only
// makes every screen read once more.
//
// Like CacheInvalidation, each handler runs at once and again after the
// transaction commits, so a screen that looked while the write was still
// uncommitted is told again once it is real. Writes Laravel fires no event
// for (query-builder updates) are not seen; the screens' own slow poll
// still catches those.
final class LivePulse
{
    private const TTL = 86400;

    /**
     * The areas a screen can follow:
     *  - visits: bookings, their services and staff, the queue
     *  - billing: bills, payments, bill adjustments
     *  - attendance: time in/out, leave, time off
     *  - clients: clients and their points, vouchers and memberships
     *  - requests: catalog, program, schedule-change and transfer requests
     *  - setup: what a client sees of the business (branches, staff, their schedules, services,
     *    packages, programs, facilities, hours, settings — AppCache::bumpBusiness), accounts,
     *    commission and penalty rules, time off
     *  - plan: the subscription and its add-ons
     *  - audit: the business's activity log (one row per change of any kind)
     *  - notifications: a notification for someone at the business arrived or was read (the bell)
     */
    public const TOPICS = ['visits', 'billing', 'attendance', 'clients', 'requests', 'setup', 'plan', 'audit', 'notifications'];

    /** The overall token: changes with every area. */
    public static function token(int $businessId): string
    {
        $key = self::key($businessId);
        Cache::add($key, Str::random(10), self::TTL);

        return (string) Cache::get($key);
    }

    /** @return array<string, string> each area's token */
    public static function topics(int $businessId): array
    {
        $keys = array_map(fn (string $t) => self::key($businessId, $t), self::TOPICS);
        $have = Cache::many($keys);
        $out = [];
        foreach (self::TOPICS as $i => $topic) {
            $value = $have[$keys[$i]] ?? null;
            if ($value === null) {
                Cache::add($keys[$i], Str::random(10), self::TTL);
                $value = Cache::get($keys[$i]);
            }
            $out[$topic] = (string) $value;
        }

        return $out;
    }

    /** Something in these areas changed (and so the overall token too). */
    public static function bump(?int $businessId, string $topic, string ...$more): void
    {
        if (! $businessId) {
            return;
        }
        Cache::put(self::key($businessId), Str::random(10), self::TTL);
        foreach ([$topic, ...$more] as $t) {
            Cache::put(self::key($businessId, $t), Str::random(10), self::TTL);
        }
    }

    private static function key(int $businessId, ?string $topic = null): string
    {
        return $topic ? "live:biz:{$businessId}:{$topic}" : "live:biz:{$businessId}";
    }

    public static function register(): void
    {
        self::on([Appointment::class, Queue::class],
            fn (Model $m) => self::bump(self::businessOfBranch($m->spa_branch_id), 'visits'));
        self::on([AppointmentServiceItem::class, AppointmentPackage::class],
            fn (Model $m) => self::bump(self::businessOfAppointment($m->appointment_id), 'visits'));
        self::on([TherapistAssignment::class], function (TherapistAssignment $a) {
            self::bump(self::businessOfAppointment(self::appointmentOfService($a->appointment_service_id)), 'visits');
        });

        self::on([Billing::class, Payment::class, BillingAdjustment::class],
            fn (Model $m) => self::bump($m->spa_business_id ?: self::businessOfBranch($m->spa_branch_id), 'billing'));

        self::on([Attendance::class], fn (Attendance $a) => self::bump(self::businessOfBranch(
            $a->spa_branch_id ?: self::branchOfStaff($a->staff_id)), 'attendance'));
        self::on([StaffTimeOff::class], fn (StaffTimeOff $t) => self::bump(self::businessOfBranch(self::branchOfStaff($t->staff_id)), 'attendance', 'setup'));

        self::on([Client::class], fn (Client $c) => self::bump($c->spa_business_id, 'clients'));
        self::on([ClientVoucher::class, ClientMembership::class, ClientPointEntry::class],
            fn (Model $m) => self::bump($m->client_id ? DB::table('clients')->where('id', $m->client_id)->value('spa_business_id') : null, 'clients'));

        self::on([CatalogRequest::class, ProgramRequest::class, StaffScheduleChangeRequest::class, StaffTransferRequest::class],
            fn (Model $m) => self::bump($m->spa_business_id ?: self::businessOfBranch($m->spa_branch_id), 'requests'));

        // The rest of the management screens. (What a client sees on a spa page —
        // branches, staff, services, packages, hours, settings — bumps 'setup'
        // through AppCache::bumpBusiness.)
        self::on([CommissionPlan::class, StaffPenaltyRate::class],
            fn (Model $m) => self::bump($m->spa_business_id, 'setup'));
        self::on([AccountBranch::class], fn (AccountBranch $a) => self::bump(self::businessOfBranch($a->spa_branch_id), 'setup'));
        self::on([Subscription::class, Addon::class], fn (Model $m) => self::bump($m->spa_business_id, 'plan'));

        // Every business change leaves an audit row: the activity log follows it, and so
        // (through the overall token) does any screen that names no areas.
        self::on([AuditLog::class], fn (Model $m) => self::bump($m->spa_business_id ?: self::businessOfBranch($m->spa_branch_id), 'audit'));

        // A notification for the owner or a staff login (a client's belongs to no business).
        self::on([Notification::class], fn (Notification $n) => self::bump(self::businessOfUser($n->user_id), 'notifications'));
    }

    // The business an owner or staff login works for (null for anyone else), remembered for a
    // day: a login stays with its business.
    private static function businessOfUser(?int $userId): ?int
    {
        if (! $userId) {
            return null;
        }

        return Cache::remember("live:user:{$userId}:biz", self::TTL, function () use ($userId) {
            $user = User::find($userId);
            if (! $user || ! in_array($user->role, ['business_owner', 'manager', 'front_officer'], true)) {
                return null;
            }

            return app(SpaBusinessRepository::class)->findForUser($user)?->id;
        });
    }

    /** @param list<class-string<Model>> $models */
    private static function on(array $models, Closure $handler): void
    {
        $run = function (Model $model) use ($handler) {
            $handler($model);
            if (DB::transactionLevel() > 0) {
                DB::afterCommit(fn () => $handler($model));
            }
        };

        foreach ($models as $class) {
            $class::saved($run);
            $class::deleted($run);
        }
    }

    // A branch never changes business, so this is remembered for a day.
    private static function businessOfBranch(?int $branchId): ?int
    {
        return $branchId
            ? Cache::remember("live:branch:{$branchId}:biz", self::TTL, fn () => CacheInvalidation::businessOfBranch($branchId))
            : null;
    }

    private static function branchOfStaff(?int $staffId): ?int
    {
        return $staffId ? DB::table('staff')->where('id', $staffId)->value('spa_branch_id') : null;
    }

    // A visit never moves branch, and a service never moves visit, so both
    // are remembered too: a Complete saves four rows and each bumps twice
    // (now and after commit), which was ten round trips to the database.
    private static function businessOfAppointment(?int $appointmentId): ?int
    {
        if (! $appointmentId) {
            return null;
        }
        $branchId = Cache::remember("live:appt:{$appointmentId}:branch", self::TTL,
            fn () => DB::table('appointments')->where('id', $appointmentId)->value('spa_branch_id'));

        return self::businessOfBranch($branchId);
    }

    private static function appointmentOfService(?int $serviceId): ?int
    {
        return $serviceId
            ? Cache::remember("live:service:{$serviceId}:appt", self::TTL,
                fn () => DB::table('appointment_services')->where('id', $serviceId)->value('appointment_id'))
            : null;
    }
}

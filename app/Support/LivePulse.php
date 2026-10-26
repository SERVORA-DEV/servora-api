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
use App\Models\CatalogRequest;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\ClientPointEntry;
use App\Models\ClientVoucher;
use App\Models\CommissionPlan;
use App\Models\Payment;
use App\Models\ProgramRequest;
use App\Models\Queue;
use App\Models\StaffPenaltyRate;
use App\Models\StaffScheduleChangeRequest;
use App\Models\StaffTransferRequest;
use App\Models\Subscription;
use App\Models\TherapistAssignment;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// "Something changed at this business." A screen that stays open (the front
// desk, the owner's boards) asks for this one cheap token every few seconds
// and reads its data again only when the token is different, so what another
// desk, the phone app or a scheduled job does shows up within moments
// instead of at the next slow poll.
//
// The token is a random string kept in the cache, replaced whenever a model
// that makes up a visit, a bill or the day's floor is saved or deleted
// (register(), called from AppServiceProvider). Asking for it reads the cache
// and nothing else, so it is safe to ask often. A cache that lost it only
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

    public static function token(int $businessId): string
    {
        $key = self::key($businessId);
        Cache::add($key, Str::random(10), self::TTL);

        return (string) Cache::get($key);
    }

    public static function bump(?int $businessId): void
    {
        if ($businessId) {
            Cache::put(self::key($businessId), Str::random(10), self::TTL);
        }
    }

    private static function key(int $businessId): string
    {
        return "live:biz:{$businessId}";
    }

    public static function register(): void
    {
        self::on([Appointment::class, Queue::class],
            fn (Model $m) => self::bump(self::businessOfBranch($m->spa_branch_id)));
        self::on([Billing::class, Payment::class, Client::class],
            fn (Model $m) => self::bump($m->spa_business_id ?: self::businessOfBranch($m->spa_branch_id)));
        self::on([AppointmentServiceItem::class, AppointmentPackage::class],
            fn (Model $m) => self::bump(self::businessOfAppointment($m->appointment_id)));
        self::on([TherapistAssignment::class], function (TherapistAssignment $a) {
            self::bump(self::businessOfAppointment(self::appointmentOfService($a->appointment_service_id)));
        });
        self::on([Attendance::class], fn (Attendance $a) => self::bump(self::businessOfBranch(
            $a->spa_branch_id ?: DB::table('staff')->where('id', $a->staff_id)->value('spa_branch_id'))));

        // The management screens: the catalog, people, requests, accounts, programs and
        // plan. (What a client sees on a spa page — branches, staff, services, packages,
        // hours, settings — bumps this through AppCache::bumpBusiness.) Every business
        // request also leaves an audit row, which catches anything not listed here.
        self::on([Addon::class, CommissionPlan::class, StaffPenaltyRate::class, Subscription::class,
            CatalogRequest::class, ProgramRequest::class, StaffScheduleChangeRequest::class, StaffTransferRequest::class, AuditLog::class],
            fn (Model $m) => self::bump($m->spa_business_id ?: self::businessOfBranch($m->spa_branch_id)));
        self::on([AccountBranch::class], fn (AccountBranch $a) => self::bump(self::businessOfBranch($a->spa_branch_id)));
        self::on([ClientVoucher::class, ClientMembership::class, ClientPointEntry::class],
            fn (Model $m) => self::bump($m->client_id ? DB::table('clients')->where('id', $m->client_id)->value('spa_business_id') : null));
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

<?php

namespace App\Support;

use App\Models\BranchPackage;
use App\Models\BranchSchedule;
use App\Models\BranchService;
use App\Models\CustomerProgram;
use App\Models\Facility;
use App\Models\OwnerIdentityVerification;
use App\Models\Package;
use App\Models\PackageServiceItem;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBranchPhoto;
use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use App\Models\Staff;
use App\Models\StaffQualification;
use App\Models\StaffSchedule;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\UserPermission;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

// Model events that keep AppCache correct. Each handler runs right away (so
// the rest of this request sees the change) and again after the transaction
// commits (so a request that cached the old value in between is corrected).
//
// Writes Laravel fires no event for — pivot syncs, query-builder updates —
// call AppCache directly where they happen.
final class CacheInvalidation
{
    public static function register(): void
    {
        self::on([SystemSetting::class], fn () => AppCache::forget('sys:settings'));

        self::on([Subscription::class], function (Subscription $s) {
            AppCache::forgetSubscription($s->spa_business_id);
            AppCache::bump('plans-public'); // subscriber counts, "most popular"
        });
        self::on([SubscriptionPlan::class], function () {
            AppCache::bump('plans');
            AppCache::bump('plans-public');
        });

        self::on([UserPermission::class], fn (UserPermission $p) => AppCache::forget("user:{$p->user_id}:perm"));
        self::on([OwnerIdentityVerification::class], fn (OwnerIdentityVerification $v) => AppCache::forget("user:{$v->user_id}:idv"));

        self::on([SpaBusinessSetting::class], function (SpaBusinessSetting $s) {
            AppCache::forget("biz:{$s->spa_business_id}:settings");
            AppCache::bumpBusiness($s->spa_business_id);
        });

        // Service templates (no business) feed the owners' template catalog.
        self::on([Service::class], function (Service $s) {
            $s->is_template ? AppCache::bump('templates') : AppCache::bumpBusiness($s->spa_business_id);
        });
        self::on([ServiceVariant::class], function (ServiceVariant $v) {
            $service = DB::table('services')->where('id', $v->service_id)->first(['spa_business_id', 'is_template']);
            $service?->is_template ? AppCache::bump('templates') : AppCache::bumpBusiness($service?->spa_business_id);
        });

        // Everything a client sees on a spa page or the marketplace.
        self::on([SpaBusiness::class], fn (SpaBusiness $b) => AppCache::bumpBusiness($b->id));
        self::on([SpaBranch::class, Package::class, CustomerProgram::class],
            fn (Model $m) => AppCache::bumpBusiness($m->spa_business_id));
        self::on([BranchSchedule::class, BranchService::class, BranchPackage::class, Facility::class, SpaBranchPhoto::class, Staff::class],
            fn (Model $m) => AppCache::bumpBusiness(self::businessOfBranch($m->spa_branch_id)));
        self::on([StaffSchedule::class, StaffQualification::class],
            fn (Model $m) => AppCache::bumpBusiness(self::businessOfStaff($m->staff_id)));
        self::on([PackageServiceItem::class],
            fn (PackageServiceItem $i) => AppCache::bumpBusiness(DB::table('packages')->where('id', $i->package_id)->value('spa_business_id')));
        self::on([Review::class], fn (Review $r) => AppCache::bumpBusiness(self::businessOfBranch(
            DB::table('appointments')->where('id', $r->appointment_id)->value('spa_branch_id'))));
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
            if (method_exists($class, 'restored')) {
                $class::restored($run);
            }
        }
    }

    public static function businessOfBranch(?int $branchId): ?int
    {
        return $branchId ? DB::table('spa_branches')->where('id', $branchId)->value('spa_business_id') : null;
    }

    private static function businessOfStaff(?int $staffId): ?int
    {
        return $staffId ? self::businessOfBranch(DB::table('staff')->where('id', $staffId)->value('spa_branch_id')) : null;
    }
}

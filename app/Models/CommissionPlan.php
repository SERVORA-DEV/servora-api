<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// A named commission rate the owner gives to staff (Starter, Senior…): a rate
// in the kind Staff Policies sets — a percentage of the price, or pesos per
// service. Whoever has no plan earns the STANDARD rate in Staff Policies,
// which is not a row here. See App\Support\CommissionShare for what a plan
// earns, and App\Service\Business\CommissionPlanService for managing them.
class CommissionPlan extends Model
{
    use HasUuids;

    protected $table = 'commission_plans';

    protected $fillable = [
        'uuid',
        'spa_business_id',
        'name',
        'rate',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function staff()
    {
        return $this->hasMany(Staff::class, 'commission_plan_id');
    }

    /**
     * Whether plans exist yet (the migration has been run). Until then a staff
     * member's rate is the typed custom one, as before.
     */
    public static function ready(): bool
    {
        $known = Cache::get('schema:commission_plans');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasTable('commission_plans') && Schema::hasColumn('staff', 'commission_plan_id');
        $ready ? Cache::forever('schema:commission_plans', true) : Cache::put('schema:commission_plans', false, 60);

        return $ready;
    }
}

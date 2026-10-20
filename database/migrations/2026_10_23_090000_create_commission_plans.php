<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Commission plans: the owner names as many as they like (Starter, Senior…),
// each a rate in the kind Staff Policies sets (a percentage, or pesos per
// service), and gives each staff member one. Nobody with none earns the
// STANDARD rate, which stays in Staff Policies and is not a row here.
//
// What a typed custom rate on a staff member used to be becomes a plan: every
// distinct custom rate gets a plan ("Custom 20%") and goes to the people who
// had it, so nobody's pay changes. staff.commission_rate is left in place but
// is no longer read once this has run (App\Models\Staff::ownCommissionRate).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            $table->string('name', 40);
            $table->decimal('rate', 10, 2);
            $table->timestamps();

            $table->unique(['spa_business_id', 'name']);
        });

        Schema::table('staff', function (Blueprint $table) {
            // Null: the standard rate.
            $table->foreignId('commission_plan_id')->nullable()->constrained('commission_plans')->nullOnDelete();
        });

        // Custom rates become plans.
        if (Schema::hasColumn('staff', 'commission_rate')) {
            $rows = DB::table('staff')
                ->join('spa_branches', 'spa_branches.id', '=', 'staff.spa_branch_id')
                ->whereNotNull('staff.commission_rate')
                ->select('staff.id as staff_id', 'staff.commission_rate', 'spa_branches.spa_business_id')
                ->get();

            foreach ($rows->groupBy('spa_business_id') as $businessId => $people) {
                $settings = DB::table('spa_business_settings')->where('spa_business_id', $businessId)->first();
                $policy = $settings ? (json_decode($settings->staff_policy ?? 'null', true) ?: []) : [];
                $fixed = ($policy['commission_type'] ?? 'percentage') === 'fixed';

                foreach ($people->groupBy(fn ($p) => (string) round((float) $p->commission_rate, 2)) as $rate => $group) {
                    $label = rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.');
                    $id = DB::table('commission_plans')->insertGetId([
                        'uuid' => (string) Str::uuid7(),
                        'spa_business_id' => $businessId,
                        'name' => $fixed ? "Custom ₱{$label}" : "Custom {$label}%",
                        'rate' => (float) $rate,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('staff')->whereIn('id', $group->pluck('staff_id'))->update(['commission_plan_id' => $id]);
                }
            }
        }

        Cache::forget('schema:commission_plans');
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_plan_id');
        });

        Schema::dropIfExists('commission_plans');

        Cache::forget('schema:commission_plans');
    }
};

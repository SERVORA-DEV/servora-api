<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Commission moves from a % of price (default_commission_percentage) to a
    // flat currency amount — dropped/re-added rather than modified in place
    // since this app has no doctrine/dbal dependency for ->change(). Also
    // adds `code` (short per-business SKU, e.g. "BM") and `loyalty_points`
    // (nullable = "not set"), for the not-yet-built loyalty program.
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('default_commission_percentage');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->after('name');
            $table->decimal('default_commission_amount', 10, 2)->nullable()->after('default_price');
            $table->unsignedInteger('loyalty_points')->nullable()->after('default_commission_amount');

            // MySQL/InnoDB allows multiple NULLs in a unique index, so this
            // naturally enforces "unique per business only when set" without
            // extra conditions — same reasoning as services' existing
            // unique(spa_business_id, name, duration_minutes).
            $table->unique(['spa_business_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['spa_business_id', 'code']);
            $table->dropColumn(['code', 'default_commission_amount', 'loyalty_points']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->decimal('default_commission_percentage', 5, 2)->nullable()->after('default_price');
        });
    }
};

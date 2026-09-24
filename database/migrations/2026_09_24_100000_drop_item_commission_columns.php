<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Commission is no longer set per service option or package (nor overridden
// per branch). It comes only from Business Settings → Staff Policies
// (commission type, default rate, and whether it applies to services,
// packages or both), so these per-item columns are dropped.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_variants', fn (Blueprint $table) => $table->dropColumn('commission_amount'));
        Schema::table('branch_services', fn (Blueprint $table) => $table->dropColumn('custom_commission'));
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn('default_commission_amount'));
        Schema::table('branch_packages', fn (Blueprint $table) => $table->dropColumn('custom_commission'));
    }

    public function down(): void
    {
        Schema::table('service_variants', fn (Blueprint $table) => $table->decimal('commission_amount', 10, 2)->nullable()->after('price'));
        Schema::table('branch_services', fn (Blueprint $table) => $table->decimal('custom_commission', 10, 2)->nullable()->after('custom_price'));
        Schema::table('packages', fn (Blueprint $table) => $table->decimal('default_commission_amount', 10, 2)->nullable()->after('default_price'));
        Schema::table('branch_packages', fn (Blueprint $table) => $table->decimal('custom_commission', 10, 2)->nullable()->after('custom_price'));
    }
};

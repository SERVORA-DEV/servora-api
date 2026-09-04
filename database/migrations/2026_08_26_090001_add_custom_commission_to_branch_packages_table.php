<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Per-branch commission override for a package — parallels custom_price
    // exactly (null = use the package's default_commission_amount), mirroring
    // the equivalent branch_services column.
    public function up(): void
    {
        Schema::table('branch_packages', function (Blueprint $table) {
            $table->decimal('custom_commission', 10, 2)->nullable()->after('custom_price');
        });
    }

    public function down(): void
    {
        Schema::table('branch_packages', function (Blueprint $table) {
            $table->dropColumn('custom_commission');
        });
    }
};

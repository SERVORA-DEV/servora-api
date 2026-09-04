<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Per-branch commission override for a service variant — parallels
    // custom_price exactly (null = use the variant's commission_amount).
    // Services only; branch_packages has no commission concept and
    // deliberately does not get this column.
    public function up(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->decimal('custom_commission', 10, 2)->nullable()->after('custom_price');
        });
    }

    public function down(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropColumn('custom_commission');
        });
    }
};

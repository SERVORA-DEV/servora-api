<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// address/city/province/postal_code were never actually captured by the
// branch location flow — LocationStep.vue only ever submits
// latitude/longitude/formatted_address (see SpaBranchLocationRequest). These
// columns were always null in practice, so they're dropped rather than kept
// around unused.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spa_branches', function (Blueprint $table) {
            $table->dropIndex(['city', 'province']);
            $table->dropColumn(['address', 'city', 'province', 'postal_code']);
        });
    }

    public function down(): void
    {
        Schema::table('spa_branches', function (Blueprint $table) {
            $table->text('address')->nullable()->after('phone_number');
            $table->string('city', 100)->nullable()->after('address');
            $table->string('province', 100)->nullable()->after('city');
            $table->string('postal_code', 10)->nullable()->after('province');

            $table->index(['city', 'province']);
        });
    }
};

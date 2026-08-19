<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Packages get the same code/commission/loyalty_points fields services
    // just got — packages never had a commission field at all before this.
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->after('name');
            $table->decimal('default_commission_amount', 10, 2)->nullable()->after('default_price');
            $table->unsignedInteger('loyalty_points')->nullable()->after('default_commission_amount');

            $table->unique(['spa_business_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropUnique(['spa_business_id', 'code']);
            $table->dropColumn(['code', 'default_commission_amount', 'loyalty_points']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// Service types (App\Support\ServiceKind): time-based, procedure-based,
// facility-based — and what a package service's extension adds on top of the
// package price. Until this has run everything is treated as time-based and a
// package service can't be extended (App\Models\Service::hasTypes).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // 'time' | 'procedure' | 'facility'
            $table->string('service_type', 20)->default('time')->after('category');
        });

        Schema::table('appointment_services', function (Blueprint $table) {
            // A package service extended to a longer length: the difference,
            // charged on top of the package (0 for everything else).
            $table->decimal('extra_charge', 10, 2)->default(0)->after('subtotal');
        });

        Cache::forget('schema:service_types');
    }

    public function down(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->dropColumn('extra_charge');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('service_type');
        });

        Cache::forget('schema:service_types');
    }
};

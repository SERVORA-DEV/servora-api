<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// What each service in a package is worth *in that package*, per one: the owner
// gives every service its minutes, quantity and now its price. The package still
// has its own price (what the client pays); these prices say how it divides —
// what a voided service takes off it, and what the same things cost together.
//
// Null (every package made before this) means the service's menu price, as
// before (App\Models\PackageServiceItem::unitPrice). Until this has run
// nothing writes it (App\Models\Package::hasLinePrice).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_services', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('quantity');
        });

        Cache::forget('schema:package_service_price');
    }

    public function down(): void
    {
        Schema::table('package_services', function (Blueprint $table) {
            $table->dropColumn('price');
        });

        Cache::forget('schema:package_service_price');
    }
};

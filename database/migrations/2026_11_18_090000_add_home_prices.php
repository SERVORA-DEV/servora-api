<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// Home service prices, in place of the flat fee per visit: what a service length, a package or an
// add-on costs when it is done at the client's home.
//
//   service_variants.home_price   a length's price at home; null = its spa price
//   service_variants.home_only    a length offered only for home visits (not at the spa)
//   packages.home_price           a package's price at home; null = its spa price
//   addons.home_price             an add-on's price at home; null = its spa price
//
// Only adds columns: nothing existing is changed or removed. Bookings keep the price they were made
// at, so old visits (and their home-service fee) stay as they were. Until this has run nothing reads
// or writes them (App\Support\HomePrices::ready).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_variants', 'home_price')) {
            Schema::table('service_variants', function (Blueprint $table) {
                $table->decimal('home_price', 10, 2)->nullable()->after('price');
            });
        }
        if (! Schema::hasColumn('service_variants', 'home_only')) {
            Schema::table('service_variants', function (Blueprint $table) {
                $table->boolean('home_only')->default(false)->after('home_price');
            });
        }
        if (! Schema::hasColumn('packages', 'home_price')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->decimal('home_price', 10, 2)->nullable()->after('default_price');
            });
        }
        if (Schema::hasTable('addons') && ! Schema::hasColumn('addons', 'home_price')) {
            Schema::table('addons', function (Blueprint $table) {
                $table->decimal('home_price', 10, 2)->nullable()->after('price');
            });
        }

        Cache::forget('schema:home_prices');
    }

    public function down(): void
    {
        foreach (['service_variants' => ['home_price', 'home_only'], 'packages' => ['home_price'], 'addons' => ['home_price']] as $table => $columns) {
            $present = array_values(array_filter($columns, fn ($c) => Schema::hasTable($table) && Schema::hasColumn($table, $c)));
            if ($present) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
            }
        }

        Cache::forget('schema:home_prices');
    }
};

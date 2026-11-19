<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The packages table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('packages')) {
            return;
        }

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id');
            $table->bigInteger('created_by')->nullable();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->decimal('default_price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->string('code', 20)->nullable();
            $table->integer('loyalty_points')->nullable();
            $table->boolean('home_service_available')->default(false);
            $table->decimal('home_price', 10, 2)->nullable();
            $table->smallInteger('client_count')->default(1);
            $table->unique('uuid', 'packages_uuid_unique');
            $table->foreign('spa_business_id', 'packages_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
        });

        DB::statement('CREATE UNIQUE INDEX packages_spa_business_id_code_unique ON packages (spa_business_id, code) WHERE (deleted_at IS NULL)');
        DB::statement('CREATE UNIQUE INDEX packages_spa_business_id_name_unique ON packages (spa_business_id, name) WHERE (deleted_at IS NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};

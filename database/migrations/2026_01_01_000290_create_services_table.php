<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The services table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('services')) {
            return;
        }

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id')->nullable();
            $table->bigInteger('created_by')->nullable();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->string('code', 20)->nullable();
            $table->string('image_path')->nullable();
            $table->string('category', 50)->nullable();
            $table->boolean('is_template')->default(false);
            $table->bigInteger('source_template_id')->nullable();
            $table->boolean('home_service_available')->default(false);
            $table->string('service_type', 20)->default('time');
            $table->boolean('facility_required')->default(false);
            $table->unique('uuid', 'services_uuid_unique');
            $table->index(['is_template', 'category'], 'services_is_template_category_index');
            $table->foreign('source_template_id', 'services_source_template_id_foreign')->references('id')->on('services')->onDelete('set null');
            $table->foreign('spa_business_id', 'services_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
        });

        DB::statement('CREATE UNIQUE INDEX services_spa_business_id_code_unique ON services (spa_business_id, code) WHERE (deleted_at IS NULL)');
        DB::statement('CREATE UNIQUE INDEX services_spa_business_id_name_unique ON services (spa_business_id, name) WHERE (deleted_at IS NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The service_parts table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_parts')) {
            return;
        }

        Schema::create('service_parts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('service_id');
            $table->string('name', 60);
            $table->decimal('share_percent', 5, 2);
            $table->smallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique('uuid', 'service_parts_uuid_unique');
            $table->index(['service_id', 'sort'], 'service_parts_service_id_sort_index');
            $table->foreign('service_id', 'service_parts_service_id_foreign')->references('id')->on('services')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_parts');
    }
};

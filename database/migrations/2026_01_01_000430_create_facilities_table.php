<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The facilities table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('facilities')) {
            return;
        }

        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_branch_id');
            $table->string('name', 150);
            $table->enum('status', ['Available', 'Maintenance'])->default('Available');
            $table->jsonb('amenities')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->text('description')->nullable();
            $table->boolean('is_available')->default(true);
            $table->enum('category', ['Massage', 'Facial', 'Body Treatment', 'Hair', 'Nails', 'Waxing', 'Lash & Brow', 'Makeup', 'Couples', 'VIP', 'Wellness'])->default('Massage');
            $table->smallInteger('max_clients')->default(1);
            $table->unique('uuid', 'facilities_uuid_unique');
            $table->index(['spa_branch_id', 'status'], 'facilities_spa_branch_id_status_index');
            $table->foreign('spa_branch_id', 'facilities_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('cascade');
        });

        DB::statement('CREATE UNIQUE INDEX facilities_spa_branch_id_name_unique ON facilities (spa_branch_id, name) WHERE (deleted_at IS NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('facilities');
    }
};

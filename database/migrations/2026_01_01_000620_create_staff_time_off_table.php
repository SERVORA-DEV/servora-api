<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The staff_time_off table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_time_off')) {
            return;
        }

        Schema::create('staff_time_off', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('staff_id');
            $table->string('type', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('note', 120)->nullable();
            $table->bigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->unique('uuid', 'staff_time_off_uuid_unique');
            $table->index(['staff_id', 'ends_on'], 'staff_time_off_staff_id_ends_on_index');
            $table->foreign('created_by_user_id', 'staff_time_off_created_by_user_id_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('staff_id', 'staff_time_off_staff_id_foreign')->references('id')->on('staff')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_time_off');
    }
};

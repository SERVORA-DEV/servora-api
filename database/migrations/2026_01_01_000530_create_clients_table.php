<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The clients table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clients')) {
            return;
        }

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id');
            $table->bigInteger('user_id')->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('phone_number', 20)->nullable();
            $table->string('email')->nullable();
            $table->integer('current_points')->default(0);
            $table->integer('lifetime_points')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->bigInteger('preferred_staff_id')->nullable();
            $table->bigInteger('spa_branch_id')->nullable();
            $table->string('gender', 10)->nullable();
            $table->smallInteger('birth_year')->nullable();
            $table->text('dismissed_photo')->nullable();
            $table->timestamp('photo_dismissed_at')->nullable();
            $table->unique('uuid', 'clients_uuid_unique');
            $table->index(['spa_business_id', 'email'], 'clients_spa_business_id_email_index');
            $table->index(['spa_business_id', 'phone_number'], 'clients_spa_business_id_phone_number_index');
            $table->index(['spa_business_id', 'user_id'], 'clients_spa_business_id_user_id_index');
            $table->foreign('preferred_staff_id', 'clients_preferred_staff_id_foreign')->references('id')->on('staff')->onDelete('set null');
            $table->foreign('spa_branch_id', 'clients_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('set null');
            $table->foreign('spa_business_id', 'clients_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
            $table->foreign('user_id', 'clients_user_id_foreign')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};

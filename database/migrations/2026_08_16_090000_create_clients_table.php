<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A spa's customer record — business-scoped like services/packages, since
    // the same person can be a client of multiple businesses on the
    // platform. user_id is an optional link to a login account (client
    // self-service), left null for walk-ins who never sign up.
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_business_id')
                ->constrained('spa_businesses')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();

            $table->enum('gender', ['Male', 'Female'])->nullable();

            $table->date('birth_date')->nullable();

            $table->string('phone_number', 20)->nullable();
            $table->string('email')->nullable();

            $table->string('profile_photo')->nullable();

            $table->unsignedInteger('current_points')->default(0);
            $table->unsignedInteger('lifetime_points')->default(0);

            $table->boolean('is_active')->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['spa_business_id', 'user_id']);
            $table->index(['spa_business_id', 'phone_number']);
            $table->index(['spa_business_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};

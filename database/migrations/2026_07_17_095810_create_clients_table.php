<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_business_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();

            $table->enum('gender', [
                'Male',
                'Female',
            ]);

            $table->date('birth_date')->nullable();

            $table->string('phone_number', 20);

            $table->string('email')->nullable();

            $table->string('profile_photo')->nullable();

            $table->unsignedInteger('current_points')->default(0);
            $table->unsignedInteger('lifetime_points')->default(0);

            $table->boolean('is_active')->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index([
                'spa_business_id',
                'user_id',
            ]);

            $table->unique([
                'spa_business_id',
                'phone_number',
            ]);

            $table->unique([
                'spa_business_id',
                'email',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
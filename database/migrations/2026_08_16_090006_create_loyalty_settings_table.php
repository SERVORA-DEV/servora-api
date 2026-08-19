<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // How a business's clients earn loyalty points — one row per business,
    // hence the unique spa_business_id. Only one of points_per_currency /
    // points_per_visit actually applies at a time, driven by
    // earning_method, so both are nullable rather than forcing a dummy
    // value on the unused one.
    public function up(): void
    {
        Schema::create('loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_business_id')
                ->unique()
                ->constrained('spa_businesses')
                ->cascadeOnDelete();

            $table->enum('earning_method', ['Amount', 'Service', 'Visit'])->default('Amount');

            $table->decimal('points_per_currency', 10, 2)->nullable();
            $table->unsignedInteger('points_per_visit')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_settings');
    }
};

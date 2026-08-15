<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A bundle of services sold as one offering (e.g. "Couples Retreat
    // Package") — the actual services/quantities/order come from
    // package_services. Business-scoped like services, turned on per-branch
    // via branch_packages.
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_business_id')
                ->constrained('spa_businesses')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->string('name', 150);
            $table->text('description')->nullable();

            $table->unsignedInteger('duration_minutes')->nullable();

            $table->decimal('default_price', 10, 2);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['spa_business_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};

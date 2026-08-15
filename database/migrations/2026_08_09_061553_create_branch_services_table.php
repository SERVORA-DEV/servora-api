<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Turns a business-level service on for a specific branch, with an
    // optional per-branch price override (custom_price null = use the
    // service's default_price). This is also what branch_service_facilities
    // hangs off of — which room(s) can host this service at this branch.
    public function up(): void
    {
        Schema::create('branch_services', function (Blueprint $table) {
            $table->id();

            $table->foreignId('spa_branch_id')->constrained('spa_branches')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();

            $table->decimal('custom_price', 10, 2)->nullable();

            $table->boolean('is_available')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['spa_branch_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_services');
    }
};
